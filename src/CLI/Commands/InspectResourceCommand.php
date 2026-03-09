<?php
declare(strict_types=1);

namespace Foundry\CLI\Commands;

use Foundry\CLI\Command;
use Foundry\CLI\CommandContext;
use Foundry\Compiler\CompileOptions;
use Foundry\Compiler\GraphEdge;
use Foundry\Support\FoundryError;

final class InspectResourceCommand extends Command
{
    #[\Override]
    public function matches(array $args): bool
    {
        return ($args[0] ?? null) === 'inspect' && ($args[1] ?? null) === 'resource';
    }

    #[\Override]
    public function run(array $args, CommandContext $context): array
    {
        $resource = (string) ($args[2] ?? '');
        if ($resource === '') {
            throw new FoundryError('CLI_RESOURCE_REQUIRED', 'validation', [], 'Resource name required.');
        }

        $compiler = $context->graphCompiler();
        $graph = $compiler->loadGraph();
        if ($graph === null) {
            $graph = $compiler->compile(new CompileOptions())->graph;
        }

        $resourceNode = $graph->node('resource:' . $resource);
        if ($resourceNode === null) {
            throw new FoundryError('RESOURCE_NOT_FOUND', 'not_found', ['resource' => $resource], 'Resource not found.');
        }

        $dependencies = array_values(array_map(
            static fn (GraphEdge $edge): array => $edge->toArray(),
            $graph->dependencies($resourceNode->id()),
        ));
        $dependents = array_values(array_map(
            static fn (GraphEdge $edge): array => $edge->toArray(),
            $graph->dependents($resourceNode->id()),
        ));

        $listing = $graph->node('listing_config:' . $resource)?->toArray();
        $admin = $graph->node('admin_resource:' . $resource)?->toArray();

        return [
            'status' => 0,
            'message' => null,
            'payload' => [
                'resource' => $resourceNode->toArray(),
                'listing_config' => $listing,
                'admin_resource' => $admin,
                'dependencies' => $dependencies,
                'dependents' => $dependents,
            ],
        ];
    }
}
