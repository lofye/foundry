<?php
declare(strict_types=1);

namespace Forge\Feature;

use Forge\AI\AIManager;
use Forge\Cache\CacheManager;
use Forge\DB\QueryExecutor;
use Forge\Events\EventDispatcher;
use Forge\Observability\TraceContext;
use Forge\Queue\JobDispatcher;
use Forge\Storage\StorageDriver;

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
