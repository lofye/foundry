<?php

declare(strict_types=1);

namespace Foundry\CLI\Commands;

use Foundry\CLI\Command;
use Foundry\CLI\CommandContext;
use Foundry\Event\EventRegistry;
use Foundry\Support\FoundryError;

final class EventCommand extends Command
{
    public function __construct(private readonly ?EventRegistry $registry = null) {}

    #[\Override]
    public function supportedSignatures(): array
    {
        return ['event:list', 'event:inspect'];
    }

    #[\Override]
    public function matches(array $args): bool
    {
        return in_array((string) ($args[0] ?? ''), ['event:list', 'event:inspect'], true);
    }

    #[\Override]
    public function run(array $args, CommandContext $context): array
    {
        $command = (string) ($args[0] ?? '');
        $registry = $this->registry ?? EventRegistry::forPaths($context->paths());

        return match ($command) {
            'event:list' => $this->list($registry, $context),
            'event:inspect' => $this->inspect($registry, $args, $context),
            default => throw new FoundryError('EVENT_COMMAND_INVALID', 'validation', ['command' => $command], 'Unsupported event command.'),
        };
    }

    /**
     * @return array{status:int,payload:array<string,mixed>|null,message:string|null}
     */
    private function list(EventRegistry $registry, CommandContext $context): array
    {
        $events = [];
        foreach ($registry->all() as $name => $listeners) {
            $events[] = [
                'name' => $name,
                'listener_count' => count($listeners),
            ];
        }

        $payload = ['events' => $events];

        return [
            'status' => 0,
            'payload' => $context->expectsJson() ? $payload : null,
            'message' => $context->expectsJson() ? null : $this->renderList($events),
        ];
    }

    /**
     * @param array<int,string> $args
     * @return array{status:int,payload:array<string,mixed>|null,message:string|null}
     */
    private function inspect(EventRegistry $registry, array $args, CommandContext $context): array
    {
        $event = trim((string) ($args[1] ?? ''));
        if ($event === '') {
            throw new FoundryError('EVENT_INVALID_NAME', 'validation', [], 'event:inspect requires an event name.');
        }

        $listeners = array_values(array_map(
            static fn(array $row): array => [
                'priority' => $row['priority'],
                'source' => $row['source'],
                'order' => $row['order'],
            ],
            $registry->listenersFor($event),
        ));

        $payload = [
            'event' => $event,
            'listeners' => $listeners,
        ];

        return [
            'status' => 0,
            'payload' => $context->expectsJson() ? $payload : null,
            'message' => $context->expectsJson() ? null : $this->renderInspect($event, $listeners),
        ];
    }

    /**
     * @param list<array{name:string,listener_count:int}> $events
     */
    private function renderList(array $events): string
    {
        if ($events === []) {
            return 'No events registered.';
        }

        $lines = ['Registered events:'];
        foreach ($events as $event) {
            $lines[] = '- ' . $event['name'] . ' (' . $event['listener_count'] . ' listeners)';
        }

        return implode(PHP_EOL, $lines);
    }

    /**
     * @param list<array{priority:int,source:?string,order:int}> $listeners
     */
    private function renderInspect(string $event, array $listeners): string
    {
        $lines = ['Event: ' . $event];
        if ($listeners === []) {
            $lines[] = 'Listeners: 0';

            return implode(PHP_EOL, $lines);
        }

        $lines[] = 'Listeners:';
        foreach ($listeners as $listener) {
            $lines[] = '- priority=' . $listener['priority']
                . ' order=' . $listener['order']
                . ' source=' . (string) ($listener['source'] ?? 'none');
        }

        return implode(PHP_EOL, $lines);
    }
}
