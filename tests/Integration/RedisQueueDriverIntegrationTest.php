<?php
declare(strict_types=1);

namespace Foundry\Tests\Integration;

use Foundry\Queue\RedisQueueDriver;
use Foundry\Support\FoundryError;
use PHPUnit\Framework\TestCase;

final class RedisQueueDriverIntegrationTest extends TestCase
{
    public function test_enqueue_dequeue_and_inspect_against_redis(): void
    {
        $prefix = 'foundry:test:queue:' . bin2hex(random_bytes(3));
        $queue = 'default_' . bin2hex(random_bytes(3));
        $driver = $this->redisDriver($prefix);

        $driver->enqueue($queue, 'job.first', ['id' => '1']);
        $driver->enqueue($queue, 'job.second', ['id' => '2']);

        $snapshot = $driver->inspect($queue);
        $this->assertCount(2, $snapshot);
        $this->assertSame('job.first', $snapshot[0]['job']);
        $this->assertSame(['id' => '1'], $snapshot[0]['payload']);

        $first = $driver->dequeue($queue);
        $second = $driver->dequeue($queue);

        $this->assertSame('job.first', $first['job'] ?? null);
        $this->assertSame(['id' => '1'], $first['payload'] ?? null);
        $this->assertSame('job.second', $second['job'] ?? null);
        $this->assertSame(['id' => '2'], $second['payload'] ?? null);
        $this->assertNull($driver->dequeue($queue));
    }

    public function test_inspect_ignores_corrupt_payloads(): void
    {
        $prefix = 'foundry:test:queue:' . bin2hex(random_bytes(3));
        $queue = 'default_' . bin2hex(random_bytes(3));
        $redis = $this->redisClient();
        $key = rtrim($prefix, ':') . ':' . $queue;

        $redis->del($key);
        $redis->rPush($key, '{"job":"ok","payload":{"x":"1"}}');
        $redis->rPush($key, 'not-json');

        $driver = new RedisQueueDriver($redis, $prefix);
        $snapshot = $driver->inspect($queue);

        $this->assertCount(1, $snapshot);
        $this->assertSame('ok', $snapshot[0]['job']);
    }

    public function test_constructor_connects_without_injected_client(): void
    {
        if (!class_exists(\Redis::class)) {
            self::markTestSkipped('Redis extension is not available.');
        }

        $prefix = 'foundry:test:queue:' . bin2hex(random_bytes(3));
        $queue = 'default_' . bin2hex(random_bytes(3));
        try {
            $driver = new RedisQueueDriver(
                null,
                $prefix,
                '127.0.0.1',
                6379,
                1.0,
                0,
            );
        } catch (FoundryError $e) {
            self::markTestSkipped('Redis is not reachable: ' . $e->getMessage());
        }

        $driver->enqueue($queue, 'job.connect', ['ok' => true]);
        $job = $driver->dequeue($queue);

        $this->assertSame('job.connect', $job['job'] ?? null);
        $this->assertSame(['ok' => true], $job['payload'] ?? null);
    }

    public function test_constructor_throws_on_connection_failure(): void
    {
        if (!class_exists(\Redis::class)) {
            self::markTestSkipped('Redis extension is not available.');
        }

        $this->expectException(FoundryError::class);
        $this->expectExceptionMessage('Failed to connect to Redis.');

        new RedisQueueDriver(
            null,
            'foundry:test:queue:' . bin2hex(random_bytes(3)),
            '127.0.0.1',
            6390,
            0.2,
            0,
        );
    }

    public function test_blocking_dequeue_returns_null_when_queue_is_empty(): void
    {
        $driver = new RedisQueueDriver(
            $this->redisClient(),
            'foundry:test:queue:' . bin2hex(random_bytes(3)),
            blockSeconds: 1,
        );

        $this->assertNull($driver->dequeue('empty_' . bin2hex(random_bytes(3))));
    }

    public function test_dequeue_throws_for_invalid_payload_shape(): void
    {
        $prefix = 'foundry:test:queue:' . bin2hex(random_bytes(3));
        $queue = 'default_' . bin2hex(random_bytes(3));
        $redis = $this->redisClient();
        $key = rtrim($prefix, ':') . ':' . $queue;

        $redis->del($key);
        $redis->rPush($key, '{"payload":{"ok":true}}');

        $driver = new RedisQueueDriver($redis, $prefix);

        $this->expectException(FoundryError::class);
        $this->expectExceptionMessage('Dequeued Redis payload is invalid.');
        $driver->dequeue($queue);
    }

    private function redisDriver(string $prefix): RedisQueueDriver
    {
        return new RedisQueueDriver(
            $this->redisClient(),
            $prefix,
        );
    }

    private function redisClient(): \Redis
    {
        if (!class_exists(\Redis::class)) {
            self::markTestSkipped('Redis extension is not available.');
            throw new \RuntimeException('Unreachable');
        }

        $redis = new \Redis();

        try {
            $connected = $redis->connect('127.0.0.1', 6379, 1.0);
        } catch (\Throwable $e) {
            self::markTestSkipped('Redis is not reachable: ' . $e->getMessage());
            throw new \RuntimeException('Unreachable');
        }

        if ($connected !== true) {
            self::markTestSkipped('Redis is not reachable.');
            throw new \RuntimeException('Unreachable');
        }

        try {
            $pong = $redis->ping();
        } catch (\Throwable) {
            self::markTestSkipped('Redis ping failed.');
            throw new \RuntimeException('Unreachable');
        }

        if ($pong !== true && (!is_string($pong) || stripos($pong, 'PONG') === false)) {
            self::markTestSkipped('Redis ping failed.');
            throw new \RuntimeException('Unreachable');
        }

        return $redis;
    }
}
