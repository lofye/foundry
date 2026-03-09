<?php
declare(strict_types=1);

namespace Foundry\CLI\Commands;

use Foundry\CLI\Command;
use Foundry\CLI\CommandContext;
use Foundry\Compiler\CompileOptions;
use Foundry\Compiler\Visualization\GraphVisualizer;
use Foundry\Support\FoundryError;

final class GraphVisualizeCommand extends Command
{
    /**
     * @var array<int,string>
     */
    private array $allowedFormats = ['mermaid', 'dot', 'json', 'svg'];

    #[\Override]
    public function matches(array $args): bool
    {
        return ($args[0] ?? null) === 'graph' && ($args[1] ?? null) === 'visualize';
    }

    #[\Override]
    public function run(array $args, CommandContext $context): array
    {
        [$view, $format, $feature] = $this->parseOptions($args);

        if (!in_array($format, $this->allowedFormats, true)) {
            throw new FoundryError(
                'CLI_GRAPH_FORMAT_INVALID',
                'validation',
                ['format' => $format],
                'Unsupported graph format. Use mermaid, dot, json, or svg.',
            );
        }

        if ($feature !== null && !$this->featureExists($context, $feature)) {
            throw new FoundryError(
                'FEATURE_NOT_FOUND',
                'not_found',
                ['feature' => $feature],
                'Feature not found.',
            );
        }

        $compiler = $context->graphCompiler();
        $graph = $compiler->loadGraph() ?? $compiler->compile(new CompileOptions())->graph;

        $visualizer = new GraphVisualizer();
        $graphData = $visualizer->build($graph, $view, $feature);
        $rendered = $visualizer->render($graphData, $format);

        return [
            'status' => 0,
            'message' => $rendered,
            'payload' => [
                'graph_version' => $graph->graphVersion(),
                'framework_version' => $graph->frameworkVersion(),
                'compiled_at' => $graph->compiledAt(),
                'source_hash' => $graph->sourceHash(),
                'view' => $view,
                'format' => $format,
                'feature_filter' => $feature,
                'graph' => $graphData,
                'rendered' => $rendered,
            ],
        ];
    }

    /**
     * @param array<int,string> $args
     * @return array{0:string,1:string,2:?string}
     */
    private function parseOptions(array $args): array
    {
        $view = 'dependencies';
        $format = 'mermaid';
        $feature = null;

        foreach ($args as $index => $arg) {
            if ($arg === '--events') {
                $view = 'events';
                continue;
            }

            if ($arg === '--routes') {
                $view = 'routes';
                continue;
            }

            if ($arg === '--caches') {
                $view = 'caches';
                continue;
            }

            if ($arg === '--pipeline') {
                $view = 'pipeline';
                continue;
            }

            if (str_starts_with($arg, '--format=')) {
                $format = strtolower((string) substr($arg, strlen('--format=')));
                continue;
            }

            if ($arg === '--format') {
                $value = strtolower((string) ($args[$index + 1] ?? ''));
                if ($value !== '') {
                    $format = $value;
                }
                continue;
            }

            if (str_starts_with($arg, '--feature=')) {
                $feature = substr($arg, strlen('--feature='));
                continue;
            }

            if ($arg === '--feature') {
                $value = (string) ($args[$index + 1] ?? '');
                if ($value !== '') {
                    $feature = $value;
                }
            }
        }

        if ($feature === '') {
            $feature = null;
        }

        return [$view, $format, $feature];
    }

    private function featureExists(CommandContext $context, string $feature): bool
    {
        return is_dir($context->paths()->features() . '/' . $feature);
    }
}
