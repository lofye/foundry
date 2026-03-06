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

final class DefaultFeatureServices implements FeatureServices
{
    public function __construct(
        private readonly QueryExecutor $db,
        private readonly CacheManager $cache,
        private readonly JobDispatcher $jobs,
        private readonly EventDispatcher $events,
        private readonly StorageDriver $storage,
        private readonly TraceContext $trace,
        private readonly AIManager $ai,
    ) {
    }

    public function db(): QueryExecutor
    {
        return $this->db;
    }

    public function cache(): CacheManager
    {
        return $this->cache;
    }

    public function jobs(): JobDispatcher
    {
        return $this->jobs;
    }

    public function events(): EventDispatcher
    {
        return $this->events;
    }

    public function storage(): StorageDriver
    {
        return $this->storage;
    }

    public function trace(): TraceContext
    {
        return $this->trace;
    }

    public function ai(): AIManager
    {
        return $this->ai;
    }
}
