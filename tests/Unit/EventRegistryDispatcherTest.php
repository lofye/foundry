<?php

declare(strict_types=1);

namespace Foundry\Tests\Unit;

use Foundry\Event\EventDispatcher;
use Foundry\Event\EventRegistry;
use Foundry\Support\FoundryError;
use PHPUnit\Framework\TestCase;

final class EventRegistryDispatcherTest extends TestCase
{
    public function test_registry_orders_by_priority_then_registration_order(): void
    {
        $registry = new EventRegistry();
        $calls = [];

        $registry->register('feature.created', function () use (&$calls): void { $calls[] = 'b'; }, priority: 0, source: 'feature:b');
        $registry->register('feature.created', function () use (&$calls): void { $calls[] = 'a'; }, priority: 10, source: 'feature:a');
        $registry->register('feature.created', function () use (&$calls): void { $calls[] = 'c'; }, priority: 0, source: 'feature:c');

        (new EventDispatcher($registry))->dispatch('feature.created', []);

        $this->assertSame(['a', 'b', 'c'], $calls);

        $listeners = $registry->listenersFor('feature.created');
        $this->assertSame(10, $listeners[0]['priority']);
        $this->assertSame(0, $listeners[1]['priority']);
        $this->assertLessThan($listeners[2]['order'], $listeners[1]['order']);
    }

    public function test_registry_validates_inputs_and_registration_phase(): void
    {
        $registry = new EventRegistry();

        try {
            $registry->register('Feature.Created', static function (array $payload): void {});
            self::fail('Expected invalid event name failure.');
        } catch (FoundryError $error) {
            $this->assertSame('EVENT_INVALID_NAME', $error->errorCode);
        }

        $registry->endBoot();

        try {
            $registry->register('feature.created', static function (array $payload): void {}, source: 'pack:blog');
            self::fail('Expected outside boot failure.');
        } catch (FoundryError $error) {
            $this->assertSame('EVENT_REGISTER_OUTSIDE_BOOT', $error->errorCode);
        }
    }

    public function test_dispatch_wraps_listener_failure_and_rejects_during_dispatch_registration(): void
    {
        $registry = new EventRegistry();

        $registry->register('feature.created', function () use ($registry): void {
            $registry->endBoot();
            $registry->register('feature.created', static function (array $payload): void {}, source: 'pack:blog');
        }, source: 'feature:test');

        try {
            (new EventDispatcher($registry))->dispatch('feature.created', []);
            self::fail('Expected dispatch failure.');
        } catch (FoundryError $error) {
            $this->assertSame('EVENT_DISPATCH_FAILED', $error->errorCode);
            $previous = $error->getPrevious();
            $this->assertInstanceOf(FoundryError::class, $previous);
            /** @var FoundryError $previous */
            $this->assertSame('EVENT_REGISTER_DURING_DISPATCH', $previous->errorCode);
        }
    }
}
