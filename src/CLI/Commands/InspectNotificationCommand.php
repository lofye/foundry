<?php

declare(strict_types=1);

namespace Foundry\CLI\Commands;

use Foundry\CLI\Command;
use Foundry\CLI\CommandContext;
use Foundry\Compiler\CompileOptions;
use Foundry\Compiler\GraphEdge;
use Foundry\Support\FoundryError;

final class InspectNotificationCommand extends Command
{
    #[\Override]
    public function supportedSignatures(): array
    {
        return ['inspect notification'];
    }

    #[\Override]
    public function matches(array $args): bool
    {
        return ($args[0] ?? null) === 'inspect' && ($args[1] ?? null) === 'notification';
    }

    #[\Override]
    public function run(array $args, CommandContext $context): array
    {
        $name = (string) ($args[2] ?? '');
        if ($name === '') {
            throw new FoundryError('CLI_NOTIFICATION_REQUIRED', 'validation', [], 'Notification name required.');
        }

        $graph = $context->graphCompiler()->loadGraph() ?? $context->graphCompiler()->compile(new CompileOptions())->graph;
        $node = $graph->node('notification:' . $name);
        if ($node === null) {
            throw new FoundryError('NOTIFICATION_NOT_FOUND', 'not_found', ['notification' => $name], 'Notification not found.');
        }

        return [
            'status' => 0,
            'message' => null,
            'payload' => [
                'notification' => $node->toArray(),
                'dependencies' => array_values(array_map(static fn(GraphEdge $edge): array => $edge->toArray(), $graph->dependencies($node->id()))),
                'dependents' => array_values(array_map(static fn(GraphEdge $edge): array => $edge->toArray(), $graph->dependents($node->id()))),
            ],
        ];
    }
}
