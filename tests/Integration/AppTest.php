<?php

namespace OCA\OCCWeb\Tests\Integration\Controller;

use OCP\AppFramework\App;
use Test\TestCase;

class AppTest extends TestCase {

    private $container;

    protected function setUp(): void {
        parent::setUp();
        $app = new App('occweb');
        $this->container = $app->getContainer();
    }

    public function testAppInstalled(): void {
        $appManager = $this->container->query('OCP\App\IAppManager');
        $this->assertTrue($appManager->isInstalled('occweb'));
    }

}
