<?php
declare(strict_types=1);

namespace Foundry\Queue;

use Foundry\Support\FoundryError;
use Foundry\Support\Json;

final class RedisQueueDriver implements QueueDriver
{
    private readonly \Redis $redis;

    public function __construct(
        ?\Redis $redis = null,
        private readonly string $prefix = 'foundry:queue:',
        string $host = '127.0.0.1',
        int $port = 6379,
        float $connectTimeoutSeconds = 1.5,
        private readonly int $blockSeconds = 0,
    ) {
        if ($redis !== null) {
            $this->redis = $redis;

            return;
        }

        if (!class_exists(\Redis::class)) {
            throw new FoundryError('REDIS_EXTENSION_MISSING', 'runtime', [], 'PHP Redis extension is not installed.');
        }

        $client = new \Redis();
        try {
            $connected = $client->connect($host, $port, $connectTimeoutSeconds);
        } catch (\Throwable $e) {
            throw new FoundryError(
                'REDIS_CONNECT_FAILED',
                'runtime',
                ['host' => $host, 'port' => $port],
                'Failed to connect to Redis.',
                0,
                $e
            );
        }

        if ($connected !== true) {
            throw new FoundryError('REDIS_CONNECT_FAILED', 'runtime', ['host' => $host, 'port' => $port], 'Failed to connect to Redis.');
        }

        $this->redis = $client;
    }

    #[\Override]
    public function enqueue(string $queue, string $jobName, array $payload): void
    {
        $key = $this->queueKey($queue);
        $record = Json::encode([
            'job' => $jobName,
            'payload' => $payload,
        ]);
        $written = $this->redis->rPush($key, $record);
        if ($written === false) {
            throw new FoundryError('REDIS_ENQUEUE_FAILED', 'runtime', ['queue' => $queue], 'Failed to enqueue job to Redis.');
        }
    }

    #[\Override]
    public function dequeue(string $queue): ?array
    {
        $key = $this->queueKey($queue);

        if ($this->blockSeconds > 0) {
            $result = $this->redis->blPop([$key], $this->blockSeconds);
            if ($result === false || $result === null) {
                return null;
            }

            $raw = $result[1] ?? null;
            if ($raw === null || $raw === false) {
                return null;
            }

            if (!is_string($raw)) {
                throw new FoundryError('REDIS_DEQUEUE_INVALID', 'runtime', ['queue' => $queue], 'Dequeued Redis payload is invalid.');
            }

            return $this->decodeJobRecord($raw, $queue);
        }

        $raw = $this->redis->lPop($key);
        if ($raw === false || $raw === null) {
            return null;
        }

        if (!is_string($raw)) {
            throw new FoundryError('REDIS_DEQUEUE_INVALID', 'runtime', ['queue' => $queue], 'Dequeued Redis payload is invalid.');
        }

        return $this->decodeJobRecord($raw, $queue);
    }

    #[\Override]
    public function inspect(string $queue): array
    {
        $rawRecords = $this->redis->lRange($this->queueKey($queue), 0, -1);
        if (!is_array($rawRecords)) {
            return [];
        }

        $records = [];
        foreach ($rawRecords as $rawRecord) {
            if (!is_string($rawRecord)) {
                continue;
            }

            try {
                $records[] = $this->decodeJobRecord($rawRecord, $queue);
            } catch (FoundryError) {
                continue;
            }
        }

        return $records;
    }

    private function queueKey(string $queue): string
    {
        return rtrim($this->prefix, ':') . ':' . $queue;
    }

    /**
     * @return array{job:string,payload:array<string,mixed>}
     */
    private function decodeJobRecord(string $rawRecord, string $queue): array
    {
        $decoded = Json::decodeAssoc($rawRecord);
        $job = $decoded['job'] ?? null;
        $payload = $decoded['payload'] ?? null;

        if (!is_string($job) || !is_array($payload)) {
            throw new FoundryError('REDIS_DEQUEUE_INVALID', 'runtime', ['queue' => $queue], 'Dequeued Redis payload is invalid.');
        }

        return [
            'job' => $job,
            'payload' => $payload,
        ];
    }
}
