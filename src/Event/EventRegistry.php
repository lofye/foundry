<?php

declare(strict_types=1);

namespace Foundry\Event;

use Foundry\Support\FoundryError;
use Foundry\Support\Paths;

final class EventRegistry
{
    /**
     * @var array<string,list<array{listener:callable,priority:int,source:?string,order:int}>>
     */
    private array $listeners = [];

    private int $order = 0;
    private bool $bootPhase = true;
    private bool $dispatching = false;

    public static function forPaths(Paths $paths): self
    {
        $registry = new self();

        foreach (['foundry.events.php', 'config/foundry/event-listeners.php'] as $relative) {
            $path = $paths->join($relative);
            if (!is_file($path)) {
                continue;
            }

            $bootstrap = require $path;
            if (!is_callable($bootstrap)) {
                throw new FoundryError(
                    'EVENT_BOOTSTRAP_INVALID',
                    'validation',
                    ['path' => $relative],
                    'Event bootstrap file must return a callable.',
                );
            }

            $bootstrap($registry);
        }

        $registry->endBoot();

        return $registry;
    }

    public function beginBoot(): void
    {
        $this->bootPhase = true;
    }

    public function endBoot(): void
    {
        $this->bootPhase = false;
    }

    public function register(string $event, callable $listener, int $priority = 0, ?string $source = null): void
    {
        $event = trim($event);
        if (!preg_match('/^[a-z][a-z0-9]*(\.[a-z0-9]+)*$/', $event)) {
            throw new FoundryError('EVENT_INVALID_NAME', 'validation', ['event' => $event], 'Event name must be lowercase dot-separated identifier.');
        }

        if (!is_callable($listener)) {
            throw new FoundryError('EVENT_LISTENER_INVALID', 'validation', ['event' => $event], 'Event listener must be callable.');
        }

        if ($source !== null) {
            $source = trim($source);
            if ($source === '') {
                throw new FoundryError('EVENT_SOURCE_INVALID', 'validation', ['event' => $event], 'Event source must be a stable non-empty string when provided.');
            }
        }

        if ($this->dispatching && !$this->bootPhase) {
            throw new FoundryError('EVENT_REGISTER_DURING_DISPATCH', 'validation', ['event' => $event, 'source' => $source], 'Registering listeners during active dispatch is not allowed outside boot phase.');
        }

        if (!$this->bootPhase && $source !== null) {
            throw new FoundryError('EVENT_REGISTER_OUTSIDE_BOOT', 'validation', ['event' => $event, 'source' => $source], 'Pack/provider listener registration is only allowed during boot/registration phase.');
        }

        $this->listeners[$event] ??= [];
        $this->listeners[$event][] = [
            'listener' => $listener,
            'priority' => $priority,
            'source' => $source,
            'order' => ++$this->order,
        ];

        usort(
            $this->listeners[$event],
            static fn(array $a, array $b): int => $b['priority'] <=> $a['priority'] ?: $a['order'] <=> $b['order'],
        );
    }

    public function beginDispatch(): void
    {
        $this->dispatching = true;
    }

    public function endDispatch(): void
    {
        $this->dispatching = false;
    }

    /**
     * @return list<array{listener:callable,priority:int,source:?string,order:int}>
     */
    public function listenersFor(string $event): array
    {
        return $this->listeners[$event] ?? [];
    }

    /**
     * @return array<string,list<array{listener:callable,priority:int,source:?string,order:int}>>
     */
    public function all(): array
    {
        $all = $this->listeners;
        ksort($all);

        return $all;
    }
}
