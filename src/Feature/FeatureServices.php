<?php

declare(strict_types=1);

namespace Foundry\Feature;

use Foundry\AI\AIManager;
use Foundry\Cache\CacheManager;
use Foundry\DB\QueryExecutor;
use Foundry\Events\EventDispatcher;
use Foundry\Observability\TraceContext;
use Foundry\Queue\JobDispatcher;
use Foundry\Storage\StorageDriver;

interface FeatureServices
{
    public function db(): QueryExecutor;

    public function cache(): CacheManager;

    public function jobs(): JobDispatcher;

    public function events(): EventDispatcher;

    public function storage(): StorageDriver;

    public function trace(): TraceContext;

    public function ai(): AIManager;
}
