<?php

declare(strict_types=1);

namespace Foundry\CLI\Commands;

use Foundry\CLI\Command;
use Foundry\CLI\CommandContext;
use Foundry\Compiler\CompileOptions;
use Foundry\Compiler\GraphEdge;
use Foundry\Support\FoundryError;

final class InspectPlatformCommand extends Command
{
    #[\Override]
    public function supportedSignatures(): array
    {
        return ['inspect billing', 'inspect workflow', 'inspect orchestration', 'inspect search', 'inspect streams', 'inspect locales', 'inspect roles'];
    }

    #[\Override]
    public function matches(array $args): bool
    {
        if (($args[0] ?? null) !== 'inspect') {
            return false;
        }

        return $this->supportsSignature('inspect ' . (string) ($args[1] ?? ''));
    }

    #[\Override]
    public function run(array $args, CommandContext $context): array
    {
        $target = (string) ($args[1] ?? '');
        $graph = $context->graphCompiler()->loadGraph() ?? $context->graphCompiler()->compile(new CompileOptions())->graph;

        return match ($target) {
            'billing' => $this->inspectBilling($graph, $this->extractOption($args, '--provider')),
            'workflow' => $this->inspectNode($graph, 'workflow', (string) ($args[2] ?? '')),
            'orchestration' => $this->inspectNode($graph, 'orchestration', (string) ($args[2] ?? '')),
            'search' => $this->inspectNode($graph, 'search_index', (string) ($args[2] ?? '')),
            'streams' => $this->inspectList($graph, 'stream', 'streams'),
            'locales' => $this->inspectList($graph, 'locale_bundle', 'locales'),
            'roles' => $this->inspectRoles($graph),
            default => throw new FoundryError('CLI_INSPECT_TARGET_INVALID', 'validation', ['target' => $target], 'Unsupported inspect target.'),
        };
    }

    private function inspectBilling(\Foundry\Compiler\ApplicationGraph $graph, ?string $provider): array
    {
        if ($provider !== null && $provider !== '') {
            return $this->inspectNode($graph, 'billing', $provider);
        }

        return $this->inspectList($graph, 'billing', 'billing');
    }

    private function inspectNode(\Foundry\Compiler\ApplicationGraph $graph, string $type, string $name): array
    {
        if ($name === '') {
            throw new FoundryError('CLI_INSPECT_NAME_REQUIRED', 'validation', ['type' => $type], 'Inspect target name required.');
        }

        $nodeId = $type . ':' . $name;
        $node = $graph->node($nodeId);
        if ($node === null) {
            throw new FoundryError('INSPECT_NODE_NOT_FOUND', 'not_found', ['node_id' => $nodeId], 'Node not found.');
        }

        return [
            'status' => 0,
            'message' => null,
            'payload' => [
                'node' => $node->toArray(),
                'dependencies' => array_values(array_map(static fn(GraphEdge $edge): array => $edge->toArray(), $graph->dependencies($nodeId))),
                'dependents' => array_values(array_map(static fn(GraphEdge $edge): array => $edge->toArray(), $graph->dependents($nodeId))),
            ],
        ];
    }

    private function inspectList(\Foundry\Compiler\ApplicationGraph $graph, string $type, string $payloadKey): array
    {
        $rows = [];
        foreach ($graph->nodesByType($type) as $node) {
            $rows[] = $node->toArray();
        }

        return [
            'status' => 0,
            'message' => null,
            'payload' => [
                $payloadKey => $rows,
            ],
        ];
    }

    private function inspectRoles(\Foundry\Compiler\ApplicationGraph $graph): array
    {
        $roles = [];
        foreach ($graph->nodesByType('role') as $node) {
            $roles[] = $node->toArray();
        }

        $policies = [];
        foreach ($graph->nodesByType('policy') as $node) {
            $policies[] = $node->toArray();
        }

        return [
            'status' => 0,
            'message' => null,
            'payload' => [
                'roles' => $roles,
                'policies' => $policies,
            ],
        ];
    }

    /**
     * @param array<int,string> $args
     */
    private function extractOption(array $args, string $name): ?string
    {
        foreach ($args as $index => $arg) {
            if (str_starts_with($arg, $name . '=')) {
                $value = substr($arg, strlen($name . '='));

                return $value !== '' ? $value : null;
            }

            if ($arg === $name) {
                $value = (string) ($args[$index + 1] ?? '');

                return $value !== '' ? $value : null;
            }
        }

        return null;
    }
}
