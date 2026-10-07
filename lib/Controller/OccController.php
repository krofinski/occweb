<?php

namespace OCA\OCCWeb\Controller;

use Throwable;
use ReflectionClass;
use ReflectionProperty;
use ReflectionNamedType;
use OC;
use OC\Console\Application;
use OCP\IRequest;
use OCP\IGroupManager;
use OCP\IUserSession;
use OCP\IURLGenerator;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Http\RedirectResponse;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use Symfony\Component\Console\Input\StringInput;
use Symfony\Component\Console\Output\OutputInterface;
use Psr\Log\LoggerInterface;

class OccController extends Controller
{
    private LoggerInterface $logger;
    private ?string $userId;
    private ?IGroupManager $groupManager;
    private $application;
    private $symphonyApplication;
    private ?OccOutput $output = null;

    public function __construct(
        string $AppName,
        IRequest $request,
        ?string $userId = null,
        ?IGroupManager $groupManager = null,
        ?LoggerInterface $logger = null
    ) {
        parent::__construct($AppName, $request);
        $this->userId = $userId;
        $this->logger = $logger ?? OC::$server->get(LoggerInterface::class);
        $this->groupManager = $groupManager ?? (OC::$server->has(IGroupManager::class) ? OC::$server->get(IGroupManager::class) : null);

        $this->initConsoleApplication();
    }

    private function initConsoleApplication(): void
    {
        $this->output = new OccOutput(OutputInterface::VERBOSITY_NORMAL, true);

        try {
            if (OC::$server->has(Application::class)) {
                $this->application = OC::$server->get(Application::class);
            } else {
                $this->application = $this->createApplicationFallback();
            }
        } catch (Throwable $e) {
            $this->logger->info('DI container get(Application::class) fallback: ' . $e->getMessage(), ['exception' => $e]);
            $this->application = $this->createApplicationFallback();
        }

        $reflectionProperty = new ReflectionProperty(Application::class, 'application');
        if (\PHP_VERSION_ID < 80100) {
            $reflectionProperty->setAccessible(true);
        }
        $this->symphonyApplication = $reflectionProperty->getValue($this->application);
        $this->symphonyApplication->setAutoExit(false);

        try {
            $this->application->loadCommands(new StringInput(''), $this->output);
        } catch (\LogicException $e) {
            // Options/commands may already be loaded
        } catch (Throwable $e) {
            $this->logger->warning('loadCommands notice: ' . $e->getMessage(), ['exception' => $e]);
        }
    }

    private function createApplicationFallback(): Application
    {
        $ref = new ReflectionClass(Application::class);
        $constructor = $ref->getConstructor();
        if ($constructor === null) {
            return new Application();
        }

        $args = [];
        foreach ($constructor->getParameters() as $param) {
            $type = $param->getType();
            if ($type instanceof ReflectionNamedType && !$type->isBuiltin()) {
                $typeName = $type->getName();
                if ($typeName === IRequest::class || is_subclass_of($typeName, IRequest::class)) {
                    $args[] = new FakeRequest();
                } elseif (OC::$server->has($typeName)) {
                    $args[] = OC::$server->get($typeName);
                } elseif (OC::$server->query($typeName)) {
                    $args[] = OC::$server->query($typeName);
                } else {
                    $args[] = null;
                }
            } elseif ($param->isDefaultValueAvailable()) {
                $args[] = $param->getDefaultValue();
            } else {
                $args[] = null;
            }
        }

        return $ref->newInstanceArgs($args);
    }

    private function isAdmin(): bool
    {
        if ($this->userId === null) {
            return false;
        }
        if ($this->groupManager !== null) {
            return $this->groupManager->isAdmin($this->userId);
        }
        try {
            $userSession = OC::$server->get(IUserSession::class);
            $user = $userSession->getUser();
            return $user !== null && $user->isAdmin();
        } catch (Throwable $e) {
            return false;
        }
    }

    /**
     * @NoCSRFRequired
     */
    #[NoCSRFRequired]
    public function index()
    {
        if (!$this->isAdmin()) {
            try {
                $urlGenerator = OC::$server->get(IURLGenerator::class);
                return new RedirectResponse($urlGenerator->getAbsoluteURL('/'));
            } catch (Throwable $e) {
                return new DataResponse('Access denied: Administrator privileges required.', Http::STATUS_FORBIDDEN);
            }
        }
        return new TemplateResponse('occweb', 'index');
    }

    private function run(StringInput $input): string
    {
        try {
            $this->output = new OccOutput(OutputInterface::VERBOSITY_NORMAL, true);
            $this->symphonyApplication->run($input, $this->output);
            return $this->output->fetch();
        } catch (Throwable $ex) {
            $this->logger->error($ex->getMessage(), ['exception' => $ex]);
            return "error: " . $ex->getMessage() . "\n";
        }
    }

    public function cmd(string $command = ''): DataResponse
    {
        if (!$this->isAdmin()) {
            return new DataResponse("Error: Administrator privileges required.\n", Http::STATUS_FORBIDDEN);
        }

        $command = trim($command);
        if ($command === '') {
            $command = 'list';
        } elseif (strpos($command, 'occ ') === 0) {
            $command = substr($command, 4);
        } elseif ($command === 'occ') {
            $command = 'list';
        }

        // Safeguard against accidentally locking out web interface via maintenance mode
        if (preg_match('/^maintenance:mode\s+--on(\b|$)/', $command) && strpos($command, '--force') === false) {
            return new DataResponse(
                "Error: Enabling maintenance mode via OCCWeb will lock you out of the web interface!\n" .
                "If you are certain and have terminal access to turn it back off, re-run with --force:\n" .
                "occ " . $command . " --force\n"
            );
        }
        if (strpos($command, 'maintenance:mode') !== false && strpos($command, '--force') !== false) {
            $command = trim(str_replace('--force', '', $command));
        }

        $this->logger->debug('OCC Command: ' . $command);

        try {
            $input = new StringInput($command);
            $input->setInteractive(false);
            $response = $this->run($input);
        } catch (Throwable $ex) {
            $this->logger->error($ex->getMessage(), ['exception' => $ex]);
            $response = "error: " . $ex->getMessage() . "\n";
        }

        return new DataResponse($response);
    }

    /**
     * @NoCSRFRequired
     */
    #[NoCSRFRequired]
    public function list(): DataResponse
    {
        if (!$this->isAdmin()) {
            return new DataResponse(['error' => 'Administrator privileges required'], Http::STATUS_FORBIDDEN);
        }

        $cmds = [];
        if ($this->symphonyApplication !== null) {
            try {
                $defs = $this->symphonyApplication->all();
                foreach ($defs as $d) {
                    $cmds[] = $d->getName();
                }
            } catch (Throwable $e) {
                $this->logger->error('Failed to list commands: ' . $e->getMessage(), ['exception' => $e]);
            }
        }

        return new DataResponse($cmds);
    }
}
