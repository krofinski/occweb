<?php

namespace OCA\OCCWeb\Controller;

use OC;
use OC\AppFramework\Http\Request;
use OCP\IConfig;
use OCP\AppFramework\Http\IRequestId;

class FakeRequest extends Request {
    public $server = [
        'argv' => ['occ'],
    ];

    public function __construct() {
        try {
            if (class_exists(OC::class) && isset(OC::$server)) {
                $vars = [
                    'server' => ['argv' => ['occ']],
                    'method' => 'CLI',
                ];
                $requestId = OC::$server->has(IRequestId::class) ? OC::$server->get(IRequestId::class) : null;
                $config = OC::$server->has(IConfig::class) ? OC::$server->get(IConfig::class) : null;
                if ($requestId !== null && $config !== null) {
                    parent::__construct($vars, $requestId, $config);
                    return;
                }
            }
        } catch (\Throwable $e) {
            // Fallback for isolated unit tests or missing container services
        }
    }
}
