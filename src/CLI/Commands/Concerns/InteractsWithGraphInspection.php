<?php
declare(strict_types=1);

namespace Foundry\CLI\Commands\Concerns;

use Foundry\CLI\CommandContext;
use Foundry\Compiler\ApplicationGraph;
use Foundry\Compiler\CompileOptions;
use Foundry\Compiler\Visualization\GraphVisualizer;

trait InteractsWithGraphInspection
{
    /**
     * @param array<int,string> $args
     * @return array<string,mixed>
     */
    private function parseGraphOptions(array $args): array
    {
        $options = [
            'view' => null,
            'format' => null,
            'feature' => null,
            'extension' => null,
            'pipeline' => null,
            'command' => null,
            'event' => null,
            'workflow' => null,
            'area' => null,
            'output' => null,
        ];

        foreach ($args as $index => $arg) {
            if ($arg === '--events') {
                $options['view'] = 'events';
                continue;
            }

            if ($arg === '--routes') {
                $options['view'] = 'routes';
                continue;
            }

            if ($arg === '--caches') {
                $options['view'] = 'caches';
                continue;
            }

            if ($arg === '--pipeline') {
                $options['view'] = 'pipeline';
                continue;
            }

            if ($arg === '--workflows') {
                $options['view'] = 'workflows';
                continue;
            }

            if ($arg === '--extensions') {
                $options['view'] = 'extensions';
                continue;
            }

            if (str_starts_with($arg, '--pipeline=')) {
                $options['view'] = 'pipeline';
                $options['pipeline'] = $this->normalizeGraphOption(substr($arg, strlen('--pipeline=')));
                continue;
            }

            if (str_starts_with($arg, '--view=')) {
                $options['view'] = $this->normalizeGraphOption(substr($arg, strlen('--view=')));
                continue;
            }

            if ($arg === '--view') {
                $options['view'] = $this->normalizeGraphOption((string) ($args[$index + 1] ?? ''));
                continue;
            }

            if (str_starts_with($arg, '--format=')) {
                $options['format'] = $this->normalizeGraphOption(substr($arg, strlen('--format=')));
                continue;
            }

            if ($arg === '--format') {
                $options['format'] = $this->normalizeGraphOption((string) ($args[$index + 1] ?? ''));
                continue;
            }

            if (str_starts_with($arg, '--feature=')) {
                $options['feature'] = $this->normalizeGraphOption(substr($arg, strlen('--feature=')));
                continue;
            }

            if ($arg === '--feature') {
                $options['feature'] = $this->normalizeGraphOption((string) ($args[$index + 1] ?? ''));
                continue;
            }

            if (str_starts_with($arg, '--extension=')) {
                $options['extension'] = $this->normalizeGraphOption(substr($arg, strlen('--extension=')));
                continue;
            }

            if ($arg === '--extension') {
                $options['extension'] = $this->normalizeGraphOption((string) ($args[$index + 1] ?? ''));
                continue;
            }

            if (str_starts_with($arg, '--pipeline-stage=')) {
                $options['pipeline'] = $this->normalizeGraphOption(substr($arg, strlen('--pipeline-stage=')));
                continue;
            }

            if ($arg === '--pipeline-stage') {
                $options['pipeline'] = $this->normalizeGraphOption((string) ($args[$index + 1] ?? ''));
                continue;
            }

            if (str_starts_with($arg, '--command=')) {
                $options['command'] = $this->normalizeGraphOption(substr($arg, strlen('--command=')));
                continue;
            }

            if ($arg === '--command') {
                $options['command'] = $this->normalizeGraphOption((string) ($args[$index + 1] ?? ''));
                continue;
            }

            if (str_starts_with($arg, '--event=')) {
                $options['event'] = $this->normalizeGraphOption(substr($arg, strlen('--event=')));
                continue;
            }

            if ($arg === '--event') {
                $options['event'] = $this->normalizeGraphOption((string) ($args[$index + 1] ?? ''));
                continue;
            }

            if (str_starts_with($arg, '--workflow=')) {
                $options['workflow'] = $this->normalizeGraphOption(substr($arg, strlen('--workflow=')));
                continue;
            }

            if ($arg === '--workflow') {
                $options['workflow'] = $this->normalizeGraphOption((string) ($args[$index + 1] ?? ''));
                continue;
            }

            if (str_starts_with($arg, '--area=')) {
                $options['area'] = $this->normalizeGraphOption(substr($arg, strlen('--area=')));
                continue;
            }

            if ($arg === '--area') {
                $options['area'] = $this->normalizeGraphOption((string) ($args[$index + 1] ?? ''));
                continue;
            }

            if (str_starts_with($arg, '--output=')) {
                $options['output'] = $this->normalizeGraphOption(substr($arg, strlen('--output=')));
                continue;
            }

            if ($arg === '--output') {
                $options['output'] = $this->normalizeGraphOption((string) ($args[$index + 1] ?? ''));
            }
        }

        return $options;
    }

    /**
     * @param array<string,mixed> $options
     * @return array<string,mixed>
     */
    private function buildGraphInspectionPayload(CommandContext $context, array $options): array
    {
        $visualizer = $this->graphVisualizer();
        $graph = $this->loadOrCompileGraphForInspection($context);
        $inspection = $visualizer->inspect($graph, $options, $context->extensionRegistry()->inspectRows());

        $payload = [
            'schema_version' => $inspection['schema_version'] ?? 1,
            'graph_version' => $graph->graphVersion(),
            'framework_version' => $graph->frameworkVersion(),
            'compiled_at' => $graph->compiledAt(),
            'source_hash' => $graph->sourceHash(),
            'view' => $inspection['view'] ?? 'dependencies',
            'format' => $options['format'] ?? null,
            'feature_filter' => $inspection['filters']['feature'] ?? null,
            'extension_filter' => $inspection['filters']['extension'] ?? null,
            'pipeline_filter' => $inspection['filters']['pipeline'] ?? null,
            'command_filter' => $inspection['filters']['command'] ?? null,
            'event_filter' => $inspection['filters']['event'] ?? null,
            'workflow_filter' => $inspection['filters']['workflow'] ?? null,
            'filters' => $inspection['filters'] ?? [],
            'summary' => $inspection['summary'] ?? [],
            'graph' => $inspection['graph'] ?? [],
            'available_views' => $inspection['available_views'] ?? $visualizer->allowedViews(),
            'available_formats' => $inspection['available_formats'] ?? $visualizer->allowedFormats(),
        ];

        if (is_string($options['format'] ?? null) && $options['format'] !== '') {
            $payload['rendered'] = $visualizer->render(
                is_array($inspection['graph'] ?? null) ? $inspection['graph'] : [],
                (string) $options['format'],
            );
        }

        return $payload;
    }

    /**
     * @param array<string,mixed> $payload
     */
    private function renderGraphInspectionMessage(array $payload, bool $preferRendered, ?string $file = null): string
    {
        $visualizer = $this->graphVisualizer();
        $message = $preferRendered && is_string($payload['rendered'] ?? null)
            ? (string) $payload['rendered']
            : $visualizer->renderSummary([
                'summary' => is_array($payload['summary'] ?? null) ? $payload['summary'] : [],
                'filters' => is_array($payload['filters'] ?? null) ? $payload['filters'] : [],
            ]);

        if ($file !== null && $file !== '') {
            $message .= "\n\nfile: " . $file;
        }

        return $message;
    }

    /**
     * @param array<string,mixed> $payload
     */
    private function graphExportPath(CommandContext $context, array $payload, string $format, ?string $output = null): string
    {
        if ($output !== null && $output !== '') {
            return str_starts_with($output, '/')
                ? $output
                : $context->paths()->join($output);
        }

        $parts = ['graph', (string) ($payload['view'] ?? 'dependencies')];
        foreach (['feature_filter', 'extension_filter', 'pipeline_filter', 'command_filter', 'event_filter', 'workflow_filter'] as $key) {
            $value = trim((string) ($payload[$key] ?? ''));
            if ($value === '') {
                continue;
            }

            $parts[] = $this->sanitizeGraphFilePart($value);
        }

        $dir = $context->paths()->join('app/.foundry/build/exports');
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }

        return $dir . '/' . implode('.', $parts) . '.' . $format;
    }

    private function graphVisualizer(): GraphVisualizer
    {
        return new GraphVisualizer();
    }

    private function loadOrCompileGraphForInspection(CommandContext $context): ApplicationGraph
    {
        $compiler = $context->graphCompiler();

        return $compiler->loadGraph() ?? $compiler->compile(new CompileOptions())->graph;
    }

    private function normalizeGraphOption(string $value): ?string
    {
        $value = trim($value);

        return $value !== '' ? $value : null;
    }

    private function sanitizeGraphFilePart(string $value): string
    {
        $value = strtolower(trim($value));
        $value = preg_replace('/[^a-z0-9]+/', '-', $value) ?? 'graph';
        $value = trim($value, '-');

        return $value !== '' ? $value : 'graph';
    }
}
