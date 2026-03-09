<?php
declare(strict_types=1);

namespace Foundry\CLI\Commands;

use Foundry\CLI\Command;
use Foundry\CLI\CommandContext;
use Foundry\Compiler\CompileOptions;
use Foundry\Support\FoundryError;

final class ImpactCommand extends Command
{
    #[\Override]
    public function matches(array $args): bool
    {
        return in_array(($args[0] ?? ''), ['affected-files', 'impacted-features'], true);
    }

    #[\Override]
    public function run(array $args, CommandContext $context): array
    {
        $command = (string) ($args[0] ?? '');
        $compiler = $context->graphCompiler();
        $graph = $compiler->loadGraph() ?? $compiler->compile(new CompileOptions())->graph;

        if ($command === 'affected-files') {
            $feature = (string) ($args[1] ?? '');
            if ($feature === '') {
                throw new FoundryError('CLI_FEATURE_REQUIRED', 'validation', [], 'Feature required.');
            }

            $node = $graph->node('feature:' . $feature);
            if ($node === null) {
                throw new FoundryError('FEATURE_NOT_FOUND', 'not_found', ['feature' => $feature], 'Feature not found.');
            }

            $payload = $node->payload();
            $sourceFiles = array_values(array_map('strval', (array) ($payload['source_files'] ?? [])));
            $generatedFiles = [
                'app/.foundry/build/graph/app_graph.json',
                'app/.foundry/build/diagnostics/latest.json',
                'app/.foundry/build/manifests/compile_manifest.json',
                'app/generated/routes.php',
                'app/generated/feature_index.php',
                'app/generated/schema_index.php',
                'app/generated/permission_index.php',
                'app/generated/event_index.php',
                'app/generated/job_index.php',
                'app/generated/cache_index.php',
                'app/generated/scheduler_index.php',
                'app/generated/webhook_index.php',
                'app/generated/query_index.php',
            ];

            return [
                'status' => 0,
                'message' => null,
                'payload' => [
                    'feature' => $feature,
                    'affected_files' => array_values(array_unique(array_merge($sourceFiles, $generatedFiles))),
                ],
            ];
        }

        $needle = (string) ($args[1] ?? '');
        if ($needle === '') {
            throw new FoundryError('CLI_IMPACT_TARGET_REQUIRED', 'validation', [], 'Impact target required.');
        }

        $nodeId = str_starts_with($needle, 'event:')
            ? 'event:' . substr($needle, strlen('event:'))
            : (str_starts_with($needle, 'cache:')
                ? 'cache:' . substr($needle, strlen('cache:'))
                : 'permission:' . $needle);

        if ($graph->node($nodeId) === null) {
            return [
                'status' => 0,
                'message' => null,
                'payload' => [
                    'target' => $needle,
                    'features' => [],
                ],
            ];
        }

        $features = $compiler->impactAnalyzer()->affectedFeatures($graph, $nodeId);

        return [
            'status' => 0,
            'message' => null,
            'payload' => [
                'target' => $needle,
                'features' => $features,
            ],
        ];
    }
}
