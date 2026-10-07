<?php

namespace OCA\OCCWeb\Tests\Unit\Controller;

use PHPUnit\Framework\TestCase;
use OCP\IRequest;
use OCP\IGroupManager;
use OCA\OCCWeb\Controller\OccController;

class PageControllerTest extends TestCase {
    public function testOccOutputErrorOutput(): void {
        $output = new \OCA\OCCWeb\Controller\OccOutput();
        $this->assertSame($output, $output->getErrorOutput());
    }

    public function testFakeRequest(): void {
        $request = new \OCA\OCCWeb\Controller\FakeRequest();
        $this->assertEquals(['argv' => ['occ']], $request->server);
    }
}
