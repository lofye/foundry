<?php
declare(strict_types=1);

use Foundry\Core\RuntimeFactory;
use Foundry\Http\ResponseEmitter;
use Foundry\Support\Paths;

$projectRoot = dirname(__DIR__);
require $projectRoot . '/vendor/autoload.php';

$paths = Paths::fromCwd($projectRoot);
$kernel = RuntimeFactory::httpKernel($paths);
$request = RuntimeFactory::requestFromGlobals();
$response = $kernel->handle($request);

echo (new ResponseEmitter())->emit($response);
