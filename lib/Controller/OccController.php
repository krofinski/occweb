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

    private function getJobsBaseDir(): string
    {
        $base = sys_get_temp_dir() . '/nextcloud_occweb';
        if (!is_dir($base)) {
            @mkdir($base, 0700, true);
        }
        $this->cleanupOldJobs($base);
        return $base;
    }

    private function cleanupOldJobs(string $baseDir): void
    {
        // 5% chance per request to clean up abandoned job folders older than 1 hour
        if (random_int(1, 20) !== 1) {
            return;
        }
        $now = time();
        $items = @scandir($baseDir);
        if (!$items) {
            return;
        }
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $baseDir . '/' . $item;
            if (is_dir($path)) {
                $mtime = @filemtime($path);
                if ($mtime !== false && ($now - $mtime > 3600)) {
                    $this->deleteDirRecursively($path);
                }
            }
        }
    }

    private function deleteDirRecursively(string $dir): void
    {
        $files = @scandir($dir);
        if ($files) {
            foreach ($files as $f) {
                if ($f === '.' || $f === '..') {
                    continue;
                }
                @unlink($dir . '/' . $f);
            }
        }
        @rmdir($dir);
    }

    private function cleanupJob(string $jobId): void
    {
        if (!preg_match('/^[a-f0-9]{32}$/', $jobId)) {
            return;
        }
        $jobDir = sys_get_temp_dir() . '/nextcloud_occweb/' . $jobId;
        if (is_dir($jobDir)) {
            $this->deleteDirRecursively($jobDir);
        }
    }

    private function tokenizeCommand(string $command): array
    {
        $tokens = [];
        $len = strlen($command);
        $current = '';
        $inSingle = false;
        $inDouble = false;
        $escape = false;

        for ($i = 0; $i < $len; $i++) {
            $c = $command[$i];
            if ($escape) {
                $current .= $c;
                $escape = false;
            } elseif ($c === '\\') {
                $escape = true;
            } elseif ($c === "'" && !$inDouble) {
                $inSingle = !$inSingle;
            } elseif ($c === '"' && !$inSingle) {
                $inDouble = !$inDouble;
            } elseif (($c === ' ' || $c === "\t" || $c === "\n" || $c === "\r") && !$inSingle && !$inDouble) {
                if ($current !== '') {
                    $tokens[] = $current;
                    $current = '';
                }
            } else {
                $current .= $c;
            }
        }
        if ($current !== '') {
            $tokens[] = $current;
        }

        return $tokens;
    }

    private function getPhpBinary(): string
    {
        $bin = PHP_BINARY;
        if ($bin && is_executable($bin) && !str_contains($bin, 'fpm') && !str_contains($bin, 'cgi')) {
            return $bin;
        }
        $candidates = ['php', 'php84', 'php83', 'php82', '/usr/bin/php', '/usr/local/bin/php'];
        foreach ($candidates as $candidate) {
            $found = trim((string)@shell_exec('which ' . escapeshellarg($candidate) . ' 2>/dev/null'));
            if ($found && is_executable($found)) {
                return $found;
            }
        }
        return 'php';
    }

    private function getOccScriptPath(): ?string
    {
        $candidates = [
            (defined('OC::$SERVERROOT') || isset(OC::$SERVERROOT)) ? OC::$SERVERROOT . '/occ' : '',
            dirname(__DIR__, 4) . '/occ',
            '/app/www/public/occ',
            '/var/www/nextcloud/occ',
            '/var/www/html/occ',
        ];
        foreach ($candidates as $candidate) {
            if ($candidate !== '' && @file_exists($candidate) && @is_readable($candidate)) {
                return $candidate;
            }
        }
        return null;
    }

    private function canUseBackgroundRunner(): bool
    {
        if (!function_exists('exec')) {
            return false;
        }
        $occPath = $this->getOccScriptPath();
        return $occPath !== null && @file_exists($occPath);
    }

    private function runInProcess(StringInput $input): string
    {
        try {
            if (function_exists('set_time_limit')) {
                @set_time_limit(0);
            }
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
            return new DataResponse(['error' => 'Administrator privileges required'], Http::STATUS_FORBIDDEN);
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
            return new DataResponse([
                'status' => 'finished',
                'output' => "Error: Enabling maintenance mode via OCCWeb will lock you out of the web interface!\n" .
                    "If you are certain and have terminal access to turn it back off, re-run with --force:\n" .
                    "occ " . $command . " --force\n",
                'exitCode' => 1,
            ]);
        }
        if (strpos($command, 'maintenance:mode') !== false && strpos($command, '--force') !== false) {
            $command = trim(str_replace('--force', '', $command));
        }

        $this->logger->debug('OCC Command: ' . $command);

        if ($this->canUseBackgroundRunner()) {
            $jobId = bin2hex(random_bytes(16));
            $baseDir = $this->getJobsBaseDir();
            $jobDir = $baseDir . '/' . $jobId;
            @mkdir($jobDir, 0700, true);

            $logFile = $jobDir . '/output.log';
            $pidFile = $jobDir . '/pid.txt';
            $exitFile = $jobDir . '/exit.txt';

            $tokens = $this->tokenizeCommand($command);
            $cmdArgs = array_map('escapeshellarg', $tokens);

            $phpBin = escapeshellarg($this->getPhpBinary());
            $occScript = escapeshellarg((string)$this->getOccScriptPath());

            $cliCmd = "$phpBin $occScript --no-interaction " . implode(' ', $cmdArgs);
            $runnerScript = $jobDir . '/run.sh';
            $scriptContent = "#!/bin/sh\n" .
                "(\n" .
                "  " . $cliCmd . " > " . escapeshellarg($logFile) . " 2>&1 &\n" .
                "  CHILD_PID=\$!\n" .
                "  echo \$CHILD_PID > " . escapeshellarg($pidFile) . "\n" .
                "  wait \$CHILD_PID\n" .
                "  echo \$? > " . escapeshellarg($exitFile) . "\n" .
                ") < /dev/null > /dev/null 2>&1 &\n";
            @file_put_contents($runnerScript, $scriptContent);
            @chmod($runnerScript, 0700);

            $this->logger->debug('OCCWeb executing async command: ' . $cliCmd);
            $spawnCmd = "/bin/sh " . escapeshellarg($runnerScript) . " < /dev/null > /dev/null 2>&1 &";
            @exec($spawnCmd);

            // Wait up to 350ms to see if fast command finishes immediately
            for ($i = 0; $i < 7; $i++) {
                usleep(50000);
                if (file_exists($exitFile)) {
                    break;
                }
            }

            if (file_exists($exitFile)) {
                $output = (string)@file_get_contents($logFile);
                $exitCode = (int)trim((string)@file_get_contents($exitFile));
                $this->cleanupJob($jobId);
                return new DataResponse([
                    'status' => 'finished',
                    'output' => $output,
                    'exitCode' => $exitCode,
                ]);
            }

            // Command is still running (long operation)
            $initialOutput = '';
            $offset = 0;
            if (file_exists($logFile)) {
                $fp = @fopen($logFile, 'rb');
                if ($fp) {
                    $initialOutput = (string)@stream_get_contents($fp);
                    $offset = (int)@ftell($fp);
                    @fclose($fp);
                }
            }

            return new DataResponse([
                'status' => 'running',
                'jobId' => $jobId,
                'output' => $initialOutput,
                'offset' => $offset,
            ]);
        }

        // Fallback: in-process synchronous execution
        try {
            $input = new StringInput($command);
            $input->setInteractive(false);
            $response = $this->runInProcess($input);
            return new DataResponse([
                'status' => 'finished',
                'output' => $response,
                'exitCode' => 0,
            ]);
        } catch (Throwable $ex) {
            $this->logger->error($ex->getMessage(), ['exception' => $ex]);
            return new DataResponse([
                'status' => 'finished',
                'output' => 'error: ' . $ex->getMessage() . "\n",
                'exitCode' => 1,
            ]);
        }
    }

    /**
     * @NoCSRFRequired
     */
    #[NoCSRFRequired]
    public function poll(string $jobId = '', int $offset = 0): DataResponse
    {
        if (!$this->isAdmin()) {
            return new DataResponse(['error' => 'Administrator privileges required'], Http::STATUS_FORBIDDEN);
        }

        if (!preg_match('/^[a-f0-9]{32}$/', $jobId)) {
            return new DataResponse(['error' => 'Invalid job ID'], Http::STATUS_BAD_REQUEST);
        }

        $jobDir = sys_get_temp_dir() . '/nextcloud_occweb/' . $jobId;
        if (!is_dir($jobDir)) {
            return new DataResponse([
                'status' => 'finished',
                'output' => '',
                'offset' => $offset,
                'exitCode' => 0,
            ]);
        }

        $logFile = $jobDir . '/output.log';
        $pidFile = $jobDir . '/pid.txt';
        $exitFile = $jobDir . '/exit.txt';

        $chunk = '';
        $newOffset = $offset;
        if (file_exists($logFile)) {
            $fp = @fopen($logFile, 'rb');
            if ($fp) {
                if ($offset > 0) {
                    @fseek($fp, $offset);
                }
                $chunk = (string)@stream_get_contents($fp);
                $newOffset = (int)@ftell($fp);
                @fclose($fp);
            }
        }

        $isFinished = false;
        $exitCode = 0;
        if (file_exists($exitFile)) {
            $isFinished = true;
            $exitCode = (int)trim((string)@file_get_contents($exitFile));
        } else {
            $pid = (int)trim((string)@file_get_contents($pidFile));
            $isRunning = ($pid > 0) && (
                (function_exists('posix_kill') && @posix_kill($pid, 0)) ||
                @file_exists('/proc/' . $pid)
            );
            if (!$isRunning) {
                $isFinished = true;
                $exitCode = -1;
            }
        }

        if ($isFinished) {
            $this->cleanupJob($jobId);
        }

        return new DataResponse([
            'status' => $isFinished ? 'finished' : 'running',
            'output' => $chunk,
            'offset' => $newOffset,
            'exitCode' => $exitCode,
        ]);
    }

    public function cancel(string $jobId = ''): DataResponse
    {
        if (!$this->isAdmin()) {
            return new DataResponse(['error' => 'Administrator privileges required'], Http::STATUS_FORBIDDEN);
        }

        if (!preg_match('/^[a-f0-9]{32}$/', $jobId)) {
            return new DataResponse(['error' => 'Invalid job ID'], Http::STATUS_BAD_REQUEST);
        }

        $jobDir = sys_get_temp_dir() . '/nextcloud_occweb/' . $jobId;
        $pidFile = $jobDir . '/pid.txt';
        if (file_exists($pidFile)) {
            $pid = (int)trim((string)@file_get_contents($pidFile));
            if ($pid > 0) {
                if (function_exists('posix_kill')) {
                    @posix_kill($pid, SIGTERM);
                    usleep(150000);
                    if (@posix_kill($pid, 0)) {
                        @posix_kill($pid, SIGKILL);
                    }
                } elseif (function_exists('exec')) {
                    @exec("kill -TERM $pid 2>/dev/null");
                    usleep(150000);
                    @exec("kill -KILL $pid 2>/dev/null");
                }
            }
        }

        $this->cleanupJob($jobId);

        return new DataResponse(['status' => 'cancelled']);
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
