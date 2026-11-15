<?php

namespace OCA\OCCWeb\Tests\Unit\Controller;

use OCA\OCCWeb\Controller\FakeRequest;
use OCA\OCCWeb\Controller\OccController;
use OCA\OCCWeb\Controller\OccOutput;
use OCP\AppFramework\Http;
use OCP\IConfig;
use OCP\IGroupManager;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionClass;
use ReflectionMethod;
use Symfony\Component\Console\Application as SymfonyApplication;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class OccControllerTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempDir = sys_get_temp_dir() . '/occweb_test_' . bin2hex(random_bytes(6));
        mkdir($this->tempDir, 0777, true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->tempDir);
        parent::tearDown();
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = scandir($dir);
        if ($items === false) {
            return;
        }
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            if (is_dir($path)) {
                $this->removeDirectory($path);
            } else {
                @unlink($path);
            }
        }
        @rmdir($dir);
    }

    private function createExecutableScript(string $relativePath, string $scriptBody): string
    {
        $fullPath = $this->tempDir . '/' . ltrim($relativePath, '/');
        $dir = dirname($fullPath);
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }
        file_put_contents($fullPath, "#!/bin/sh\n" . $scriptBody . "\n");
        chmod($fullPath, 0755);
        return $fullPath;
    }

    private function createIsolatedController(
        ?string $userId = 'admin',
        bool $isAdmin = true,
        ?IConfig $config = null,
        ?LoggerInterface $logger = null
    ): OccController {
        $ref = new ReflectionClass(OccController::class);
        /** @var OccController $controller */
        $controller = $ref->newInstanceWithoutConstructor();

        $request = $this->createMock(IRequest::class);
        $groupManager = $this->createMock(IGroupManager::class);
        $groupManager->method('isAdmin')->willReturn($isAdmin);

        $logger ??= $this->createMock(LoggerInterface::class);

        $this->setPrivateProperty($controller, 'request', $request);
        $this->setPrivateProperty($controller, 'appName', 'occweb');
        $this->setPrivateProperty($controller, 'userId', $userId);
        $this->setPrivateProperty($controller, 'groupManager', $groupManager);
        $this->setPrivateProperty($controller, 'logger', $logger);
        $this->setPrivateProperty($controller, 'config', $config);
        $this->setPrivateProperty($controller, 'output', new OccOutput());

        $symfonyApp = new SymfonyApplication();
        $symfonyApp->setAutoExit(false);
        $listCmd = new class ('list') extends Command {
            protected function execute(InputInterface $input, OutputInterface $output): int
            {
                $output->writeln('in-process-list-ok');
                return Command::SUCCESS;
            }
        };
        $statusCmd = new class ('status') extends Command {
            protected function execute(InputInterface $input, OutputInterface $output): int
            {
                $output->writeln('in-process-status-ok');
                return Command::SUCCESS;
            }
        };
        $symfonyApp->addCommands([$listCmd, $statusCmd]);
        $this->setPrivateProperty($controller, 'symphonyApplication', $symfonyApp);

        return $controller;
    }

    private function setPrivateProperty(object $object, string $propertyName, mixed $value): void
    {
        $class = OccController::class;
        while ($class !== false) {
            $ref = new ReflectionClass($class);
            if ($ref->hasProperty($propertyName)) {
                $prop = $ref->getProperty($propertyName);
                $prop->setValue($object, $value);
                return;
            }
            $class = get_parent_class($class);
        }
        $this->fail("Property {$propertyName} not found on " . OccController::class);
    }

    private function callProtected(object $object, string $method, array $args = []): mixed
    {
        $ref = new ReflectionMethod($object, $method);
        return $ref->invokeArgs($object, $args);
    }

    public function testStratoCgiWithoutCgiInFilenameSelectsSiblingPhpCli(): void
    {
        $cgiBin = $this->createExecutableScript('opt/RZphp84/bin/php', <<<'SH'
printf "X-Powered-By: PHP/8.4.26\r\nContent-type: text/html; charset=UTF-8\r\n\r\n"
echo "OCCWEB_SAPI:cgi-fcgi"
SH);
        $cliBin = $this->createExecutableScript('opt/RZphp84/bin/php-cli', <<<'SH'
echo ""
echo "OCCWEB_SAPI:cli"
SH);

        $controller = $this->createIsolatedController();
        $detected = $this->callProtected($controller, 'detectPhpBinary', [$cgiBin, 'cgi-fcgi', 8, 4]);

        $this->assertSame($cliBin, $detected);
    }

    public function testSapiProbeRejectsCgiEvenWhenSapiAndFilenameDoNotContainCgiOrFpm(): void
    {
        $nonCliBin = $this->createExecutableScript('custom/bin/php', <<<'SH'
echo "OCCWEB_SAPI:cgi-fcgi"
SH);
        $cliBin = $this->createExecutableScript('custom/bin/php-cli', <<<'SH'
echo "OCCWEB_SAPI:cli"
SH);

        $controller = $this->createIsolatedController();
        $detected = $this->callProtected($controller, 'detectPhpBinary', [$nonCliBin, 'custom-web-sapi', 8, 4]);

        $this->assertSame($cliBin, $detected);
    }

    public function testCliBinaryWithStartupWarningStillMatchesAndNonZeroFails(): void
    {
        $noisyCli = $this->createExecutableScript('bin/noisy-cli', <<<'SH'
echo "PHP Warning: Module 'apcu' is already loaded in Unknown on line 0"
echo "OCCWEB_SAPI:cli"
exit 0
SH);
        $brokenCli = $this->createExecutableScript('bin/broken-cli', <<<'SH'
echo "OCCWEB_SAPI:cli"
exit 1
SH);
        $cgiRejectingDashR = $this->createExecutableScript('bin/php-cgi', <<<'SH'
echo "Usage: php-cgi [options]"
exit 1
SH);

        $controller = $this->createIsolatedController();

        $this->assertTrue($this->callProtected($controller, 'isCliBinary', [$noisyCli]));
        $this->assertFalse($this->callProtected($controller, 'isCliBinary', [$brokenCli]));
        $this->assertFalse($this->callProtected($controller, 'isCliBinary', [$cgiRejectingDashR]));
        $this->assertFalse($this->callProtected($controller, 'isCliBinary', ['']));
        $this->assertFalse($this->callProtected($controller, 'isCliBinary', [$this->tempDir . '/does-not-exist']));
    }

    public function testFpmInSbinResolvesSiblingBinCli(): void
    {
        $fpmBin = $this->createExecutableScript('opt/plesk/php/8.4/sbin/php-fpm', <<<'SH'
echo "ERROR: FPM cannot run -r"
exit 64
SH);
        $cliBin = $this->createExecutableScript('opt/plesk/php/8.4/bin/php', <<<'SH'
echo "OCCWEB_SAPI:cli"
SH);

        $controller = $this->createIsolatedController();
        $detected = $this->callProtected($controller, 'detectPhpBinary', [$fpmBin, 'fpm-fcgi', 8, 4]);

        $this->assertSame($cliBin, $detected);
    }

    public function testStandardCliAndCliServerReturnCurrentBinary(): void
    {
        $cliBin = $this->createExecutableScript('usr/bin/php', <<<'SH'
echo "OCCWEB_SAPI:cli"
SH);

        $controller = $this->createIsolatedController();

        $this->assertSame($cliBin, $this->callProtected($controller, 'detectPhpBinary', [$cliBin, 'cli', 8, 4]));
        $this->assertSame($cliBin, $this->callProtected($controller, 'detectPhpBinary', [$cliBin, 'cli-server', 8, 4]));
    }

    public function testPathFallbackPrioritizesMatchingPhpVersionOverGenericAndOlderVersions(): void
    {
        $pathDir = $this->tempDir . '/pathbin';
        $this->createExecutableScript('pathbin/php', 'echo "OCCWEB_SAPI:cgi-fcgi"');
        $this->createExecutableScript('pathbin/php8.2', 'echo "OCCWEB_SAPI:cli"');
        $php84 = $this->createExecutableScript('pathbin/php8.4', 'echo "OCCWEB_SAPI:cli"');

        $oldPath = getenv('PATH');
        putenv('PATH=' . $pathDir);
        try {
            $controller = $this->createIsolatedController();
            $detected = $this->callProtected($controller, 'detectPhpBinary', ['/nonexistent/sbin/apache2', 'apache2handler', 8, 4]);
            $this->assertSame($php84, $detected);
        } finally {
            putenv('PATH=' . ($oldPath === false ? '' : $oldPath));
        }
    }

    public function testConfiguredPhpBinaryOverridesAutoDetection(): void
    {
        $currentCli = $this->createExecutableScript('usr/bin/php', 'echo "OCCWEB_SAPI:cli"');
        $overrideCli = $this->createExecutableScript('custom/override-php', 'echo "OCCWEB_SAPI:cli"');

        $config = $this->createMock(IConfig::class);
        $config->method('getSystemValue')
            ->with('occweb.php_binary', '')
            ->willReturn($overrideCli);

        $controller = $this->createIsolatedController(config: $config);
        $detected = $this->callProtected($controller, 'detectPhpBinary', [$currentCli, 'cli', 8, 4]);

        $this->assertSame($overrideCli, $detected);
    }

    public function testInvalidConfiguredPhpBinaryLogsWarningAndFallsBack(): void
    {
        $currentCli = $this->createExecutableScript('usr/bin/php', 'echo "OCCWEB_SAPI:cli"');
        $badOverride = $this->createExecutableScript('custom/bad-cgi', 'echo "OCCWEB_SAPI:cgi-fcgi"');

        $config = $this->createMock(IConfig::class);
        $config->method('getSystemValue')
            ->with('occweb.php_binary', '')
            ->willReturn($badOverride);

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())
            ->method('warning')
            ->with($this->stringContains('occweb.php_binary'));

        $controller = $this->createIsolatedController(config: $config, logger: $logger);
        $detected = $this->callProtected($controller, 'detectPhpBinary', [$currentCli, 'cli', 8, 4]);

        $this->assertSame($currentCli, $detected);
    }

    private function createSubclassedController(?string $phpBinOverride, string $occPath): OccController
    {
        $controller = new class ($phpBinOverride, $occPath) extends OccController {
            private ?string $phpBinOverride;
            private string $occPath;

            public function __construct(?string $phpBinOverride, string $occPath)
            {
                $this->phpBinOverride = $phpBinOverride;
                $this->occPath = $occPath;
            }

            protected function detectPhpBinary(string $currentBin, string $currentSapi, int $majorVersion, int $minorVersion): ?string
            {
                return $this->phpBinOverride;
            }

            protected function getOccScriptPath(): ?string
            {
                return $this->occPath;
            }
        };

        $request = $this->createMock(IRequest::class);
        $groupManager = $this->createMock(IGroupManager::class);
        $groupManager->method('isAdmin')->willReturn(true);
        $logger = $this->createMock(LoggerInterface::class);

        $this->setPrivateProperty($controller, 'request', $request);
        $this->setPrivateProperty($controller, 'appName', 'occweb');
        $this->setPrivateProperty($controller, 'userId', 'admin');
        $this->setPrivateProperty($controller, 'groupManager', $groupManager);
        $this->setPrivateProperty($controller, 'logger', $logger);
        $this->setPrivateProperty($controller, 'config', null);
        $this->setPrivateProperty($controller, 'output', new OccOutput());

        $symfonyApp = new SymfonyApplication();
        $symfonyApp->setAutoExit(false);
        $symfonyApp->addCommands([new class ('status') extends Command {
            protected function execute(InputInterface $input, OutputInterface $output): int
            {
                $output->writeln('in-process-fallback-ok');
                return Command::SUCCESS;
            }
        }]);
        $this->setPrivateProperty($controller, 'symphonyApplication', $symfonyApp);

        return $controller;
    }

    public function testCmdFallsBackToInProcessWhenNoCliBinaryAvailable(): void
    {
        $occFile = $this->tempDir . '/occ';
        file_put_contents($occFile, "<?php echo 'should not run';\n");

        $controller = $this->createSubclassedController(null, $occFile);
        $response = $controller->cmd('status');
        $data = $response->getData();

        $this->assertSame('finished', $data['status']);
        $this->assertSame(0, $data['exitCode']);
        $this->assertStringContainsString('in-process-fallback-ok', $data['output']);
    }

    public function testBackgroundRunnerExecutesFastAndCancelsLongCommand(): void
    {
        $fakeCli = $this->createExecutableScript('bin/php-cli', <<<'SH'
# Execute passed mock occ script ($1) with remaining args
script="$1"
shift
exec "$script" "$@"
SH);
        $fakeOcc = $this->createExecutableScript('occ', <<<'SH'
if [ "$2" = "slow" ]; then
    echo "running-slow"
    sleep 5
    exit 0
fi
echo "bg-ok:$*"
exit 0
SH);

        $controller = $this->createSubclassedController($fakeCli, $fakeOcc);

        // Fast command finishes within 350ms
        $fastRes = $controller->cmd('status --output=json')->getData();
        $this->assertSame('finished', $fastRes['status']);
        $this->assertSame(0, $fastRes['exitCode']);
        $this->assertStringContainsString('bg-ok:--no-interaction status --output=json', $fastRes['output']);

        // Long command transitions to running -> poll -> cancel
        $slowRes = $controller->cmd('slow')->getData();
        $this->assertSame('running', $slowRes['status']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{32}$/', $slowRes['jobId']);

        $pollRes = $controller->poll($slowRes['jobId'], (int)($slowRes['offset'] ?? 0))->getData();
        $this->assertContains($pollRes['status'], ['running', 'finished']);

        $cancelRes = $controller->cancel($slowRes['jobId'])->getData();
        $this->assertSame('cancelled', $cancelRes['status']);
    }

    public function testAdminAuthorizationAndSafeguardsRegression(): void
    {
        $nonAdmin = $this->createIsolatedController(userId: 'user1', isAdmin: false);
        $res403 = $nonAdmin->cmd('status');
        $this->assertSame(Http::STATUS_FORBIDDEN, $res403->getStatus());

        $admin = $this->createIsolatedController(userId: 'admin', isAdmin: true);
        $this->setPrivateProperty($admin, 'resolvedPhpBinary', null);

        $maintRes = $admin->cmd('maintenance:mode --on');
        $maintData = $maintRes->getData();
        $this->assertSame(1, $maintData['exitCode']);
        $this->assertStringContainsString('--force', $maintData['output']);

        $occPrefixRes = $admin->cmd('occ status');
        $this->assertStringContainsString('in-process-status-ok', $occPrefixRes->getData()['output']);

        $emptyRes = $admin->cmd('');
        $this->assertStringContainsString('in-process-list-ok', $emptyRes->getData()['output']);

        $badPoll = $admin->poll('invalid-job-id');
        $this->assertSame(Http::STATUS_BAD_REQUEST, $badPoll->getStatus());
    }
}
