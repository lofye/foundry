#!/usr/bin/env php
<?php
declare(strict_types=1);

use Foundry\Compiler\CompileOptions;
use Foundry\Compiler\GraphCompiler;
use Foundry\Documentation\DocsSiteBuilder;
use Foundry\Support\ApiSurfaceRegistry;
use Foundry\Support\Json;
use Foundry\Support\Paths;

require dirname(__DIR__) . '/vendor/autoload.php';

$projectRoot = getcwd() ?: dirname(__DIR__);
$paths = Paths::fromCwd($projectRoot);
$compiler = new GraphCompiler($paths);
$compile = $compiler->compile(new CompileOptions());
$builder = new DocsSiteBuilder($paths, new ApiSurfaceRegistry());
$result = $builder->build($compile->graph);

echo Json::encode($result, true) . PHP_EOL;
