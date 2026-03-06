<?php
declare(strict_types=1);

namespace Forge\Tests\Unit;

use Forge\Events\DefaultEventDispatcher;
use Forge\Events\EventDefinition;
use Forge\Events\EventRegistry;
use Forge\Events\InMemoryEventCollector;
use PHPUnit\Framework\TestCase;

final class EventsTest extends TestCase
{
    public function test_emit_validates_payload_and_notifies_subscribers(): void
    {
        $registry = new EventRegistry();
        $registry->registerEvent(new EventDefinition('post.created', [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['post_id'],
            'properties' => ['post_id' => ['type' => 'string']],
        ]));

        $collector = new InMemoryEventCollector('post.created');
        $registry->registerSubscriber($collector);

        $dispatcher = new DefaultEventDispatcher($registry);
        $dispatcher->emit('post.created', ['post_id' => 'p1']);

        $this->assertCount(1, $collector->collected());
    }
}
