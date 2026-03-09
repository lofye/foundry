<?php
declare(strict_types=1);

namespace Foundry\CLI\Commands;

use Foundry\CLI\Command;
use Foundry\CLI\CommandContext;
use Foundry\Compiler\CompileOptions;
use Foundry\Compiler\GraphEdge;
use Foundry\Support\FoundryError;

final class InspectApiCommand extends Command
{
    #[\Override]
    public function matches(array $args): bool
    {
        return ($args[0] ?? null) === 'inspect' && ($args[1] ?? null) === 'api';
    }

    #[\Override]
    public function run(array $args, CommandContext $context): array
    {
        $name = (string) ($args[2] ?? '');
        if ($name === '') {
            throw new FoundryError('CLI_API_RESOURCE_REQUIRED', 'validation', [], 'API resource name required.');
        }

        $graph = $context->graphCompiler()->loadGraph() ?? $context->graphCompiler()->compile(new CompileOptions())->graph;
        $node = $graph->node('api_resource:' . $name);
        if ($node === null) {
            throw new FoundryError('API_RESOURCE_NOT_FOUND', 'not_found', ['resource' => $name], 'API resource not found.');
        }

        $featureMap = is_array($node->payload()['feature_map'] ?? null) ? $node->payload()['feature_map'] : [];
        $features = [];
        foreach ($featureMap as $operation => $featureName) {
            $feature = (string) $featureName;
            if ($feature === '') {
                continue;
            }
            $features[$operation] = [
                'feature' => $feature,
                'exists' => $graph->hasNode('feature:' . $feature),
                'execution_plan' => $graph->node('execution_plan:feature:' . $feature)?->toArray(),
            ];
        }
        ksort($features);

        return [
            'status' => 0,
            'message' => null,
            'payload' => [
                'api_resource' => $node->toArray(),
                'features' => $features,
                'dependencies' => array_values(array_map(static fn (GraphEdge $edge): array => $edge->toArray(), $graph->dependencies($node->id()))),
                'dependents' => array_values(array_map(static fn (GraphEdge $edge): array => $edge->toArray(), $graph->dependents($node->id()))),
            ],
        ];
    }
}
