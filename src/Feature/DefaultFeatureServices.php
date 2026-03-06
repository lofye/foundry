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

    #[\Override]
    public function db(): QueryExecutor
    {
        return $this->db;
    }

    #[\Override]
    public function cache(): CacheManager
    {
        return $this->cache;
    }

    #[\Override]
    public function jobs(): JobDispatcher
    {
        return $this->jobs;
    }

    #[\Override]
    public function events(): EventDispatcher
    {
        return $this->events;
    }

    #[\Override]
    public function storage(): StorageDriver
    {
        return $this->storage;
    }

    #[\Override]
    public function trace(): TraceContext
    {
        return $this->trace;
    }

    #[\Override]
    public function ai(): AIManager
    {
        return $this->ai;
    }
}
