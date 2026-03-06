<?php
declare(strict_types=1);

use Foundry\Support\Json;

require dirname(__DIR__, 3) . '/vendor/autoload.php';

http_response_code(200);
header('content-type: application/json');
echo Json::encode(['status' => 'foundry-ready']);
