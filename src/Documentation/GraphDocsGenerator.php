<?php

declare(strict_types=1);

namespace Foundry\Documentation;

use Foundry\Compiler\ApplicationGraph;
use Foundry\Compiler\IR\GraphNode;
use Foundry\Support\ApiSurfaceRegistry;
use Foundry\Support\CliCommandPrefix;
use Foundry\Support\Json;
use Foundry\Support\Paths;
use Foundry\Upgrade\FrameworkDeprecationRegistry;

final class GraphDocsGenerator
{
    public function __construct(
        private readonly Paths $paths,
        private readonly ApiSurfaceRegistry $apiSurfaceRegistry,
    ) {}

    /**
     * @return array<string,mixed>
     */
    public function generate(ApplicationGraph $graph, string $format = 'markdown'): array
    {
        $format = strtolower($format);
        if (!in_array($format, ['markdown', 'html'], true)) {
            $format = 'markdown';
        }

        $dir = $this->paths->join('docs/generated');
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }

        $docs = $this->documents($graph);

        $written = [];
        foreach ($docs as $name => $markdown) {
            $path = $dir . '/' . $name . ($format === 'html' ? '.html' : '.md');
            $content = $format === 'html' ? $this->toHtml($markdown) : $markdown;
            file_put_contents($path, $content);
            $written[] = $path;
        }

        sort($written);

        return [
            'format' => $format,
            'directory' => $dir,
            'files' => $written,
        ];
    }

    /**
     * @return array<string,string>
     */
    public function documents(ApplicationGraph $graph): array
    {
        return [
            'graph-overview' => $this->graphOverviewDoc($graph),
            'features' => $this->featuresDoc($graph),
            'routes' => $this->routesDoc($graph),
            'auth' => $this->authDoc($graph),
            'events' => $this->eventsDoc($graph),
            'jobs' => $this->jobsDoc($graph),
            'caches' => $this->cachesDoc($graph),
            'schemas' => $this->schemasDoc($graph),
            'api-surface' => $this->apiSurfaceDoc(),
            'cli-reference' => $this->cliReferenceDoc(),
            'upgrade-reference' => $this->upgradeReferenceDoc(),
            'llm-workflow' => $this->llmWorkflowDoc(),
        ];
    }

    private function graphOverviewDoc(ApplicationGraph $graph): string
    {
        $inspectSnapshot = [
            'framework_version' => $graph->frameworkVersion(),
            'graph_version' => $graph->graphVersion(),
            'compiled_at' => $graph->compiledAt(),
            'source_hash' => $graph->sourceHash(),
            'features' => $graph->features(),
            'node_counts' => $graph->nodeCountsByType(),
            'edge_counts' => $graph->edgeCountsByType(),
        ];
        $helpSnapshot = [
            'summary' => $this->apiSurfaceRegistry->cliHelpIndex()['summary'] ?? [],
        ];

        $lines = [
            '# Graph Overview',
            '',
            '## Snapshot',
            '- framework version: ' . $graph->frameworkVersion(),
            '- graph version: ' . (string) $graph->graphVersion(),
            '- compiled at: ' . $graph->compiledAt(),
            '- source hash: ' . $graph->sourceHash(),
            '- features: ' . implode(', ', $graph->features()),
            '- node counts: ' . $this->inlineMap($graph->nodeCountsByType()),
            '- edge counts: ' . $this->inlineMap($graph->edgeCountsByType()),
            '',
            '## Architecture',
            '- Features remain the authored source-of-truth units; routes, schemas, caches, jobs, and events are derived graph surfaces.',
            '- Generated docs are built from the same compiled graph used by inspect, export, verify, and runtime projection flows.',
            '- CLI reference pages are derived from the same API surface registry used by `help --json` and command classification.',
            '- Interactive architecture explorer: [Open Architecture Explorer](architecture-explorer.html)',
            '',
            '## CLI Output Snapshots',
            '### inspect graph --json',
            '```json',
            Json::encode($inspectSnapshot, true),
            '```',
            '',
            '### help --json',
            '```json',
            Json::encode($helpSnapshot, true),
            '```',
            '',
        ];

        return implode("\n", $lines) . "\n";
    }

    private function featuresDoc(ApplicationGraph $graph): string
    {
        $lines = ['# Feature Catalog', ''];

        foreach ($graph->nodesByType('feature') as $node) {
            $payload = $node->payload();
            $feature = (string) ($payload['feature'] ?? '');
            if ($feature === '') {
                continue;
            }

            $route = is_array($payload['route'] ?? null) ? $payload['route'] : [];
            $auth = is_array($payload['auth'] ?? null) ? $payload['auth'] : [];
            $database = is_array($payload['database'] ?? null) ? $payload['database'] : [];

            $lines[] = '## ' . $feature;
            $lines[] = '- explorer: ' . $this->explorerLink($node->id());
            $lines[] = '- kind: ' . (string) ($payload['kind'] ?? 'http');
            $lines[] = '- route: ' . strtoupper((string) ($route['method'] ?? '')) . ' ' . (string) ($route['path'] ?? '');
            $lines[] = '- input schema: ' . (string) ($payload['input_schema_path'] ?? '');
            $lines[] = '- output schema: ' . (string) ($payload['output_schema_path'] ?? '');
            $lines[] = '- auth required: ' . (((bool) ($auth['required'] ?? false)) ? 'yes' : 'no');
            $lines[] = '- permissions: ' . implode(', ', array_values(array_map('strval', (array) ($auth['permissions'] ?? []))));
            $lines[] = '- db reads: ' . implode(', ', array_values(array_map('strval', (array) ($database['reads'] ?? []))));
            $lines[] = '- db writes: ' . implode(', ', array_values(array_map('strval', (array) ($database['writes'] ?? []))));
            $lines[] = '- emitted events: ' . implode(', ', array_values(array_map('strval', (array) ($payload['events']['emit'] ?? []))));
            $lines[] = '- dispatched jobs: ' . implode(', ', array_values(array_map('strval', (array) ($payload['jobs']['dispatch'] ?? []))));
            $lines[] = '- tests: ' . implode(', ', array_values(array_map('strval', (array) ($payload['tests']['required'] ?? []))));
            $lines[] = '';
        }

        return implode("\n", $lines) . "\n";
    }

    private function routesDoc(ApplicationGraph $graph): string
    {
        $lines = ['# Route Catalog', ''];

        foreach ($graph->nodesByType('route') as $node) {
            $payload = $node->payload();
            $signature = (string) ($payload['signature'] ?? $node->id());
            $features = array_values(array_map('strval', (array) ($payload['features'] ?? [])));
            $lines[] = '## ' . $signature;
            $lines[] = '- explorer: ' . $this->explorerLink($node->id());
            $lines[] = '- features: ' . implode(', ', $features);

            foreach ($features as $feature) {
                $featureNode = $graph->node('feature:' . $feature);
                if (!$featureNode instanceof GraphNode) {
                    continue;
                }
                $fp = $featureNode->payload();
                $auth = is_array($fp['auth'] ?? null) ? $fp['auth'] : [];
                $lines[] = '- auth: ' . (((bool) ($auth['required'] ?? false)) ? 'required' : 'public') . ' [' . implode(', ', array_values(array_map('strval', (array) ($auth['strategies'] ?? [])))) . ']';
                $lines[] = '- input schema: ' . (string) ($fp['input_schema_path'] ?? '');
                $lines[] = '- output schema: ' . (string) ($fp['output_schema_path'] ?? '');
            }

            $lines[] = '';
        }

        return implode("\n", $lines) . "\n";
    }

    private function authDoc(ApplicationGraph $graph): string
    {
        $lines = ['# Auth Matrix', ''];
        $public = [];
        $protected = [];

        foreach ($graph->nodesByType('feature') as $node) {
            $payload = $node->payload();
            $feature = (string) ($payload['feature'] ?? '');
            if ($feature === '') {
                continue;
            }
            $auth = is_array($payload['auth'] ?? null) ? $payload['auth'] : [];
            if ((bool) ($auth['required'] ?? false)) {
                $protected[] = [
                    'feature' => $feature,
                    'strategies' => implode(', ', array_values(array_map('strval', (array) ($auth['strategies'] ?? [])))),
                    'permissions' => implode(', ', array_values(array_map('strval', (array) ($auth['permissions'] ?? [])))),
                ];
            } else {
                $public[] = $feature;
            }
        }

        sort($public);
        usort($protected, static fn(array $a, array $b): int => strcmp((string) ($a['feature'] ?? ''), (string) ($b['feature'] ?? '')));

        $lines[] = '## Protected Features';
        foreach ($protected as $row) {
            $lines[] = '- ' . (string) $row['feature'] . ': strategies=[' . (string) $row['strategies'] . '] permissions=[' . (string) $row['permissions'] . ']';
        }
        $lines[] = '';
        $lines[] = '## Public Features';
        foreach ($public as $feature) {
            $lines[] = '- ' . $feature;
        }
        $lines[] = '';

        return implode("\n", $lines) . "\n";
    }

    private function eventsDoc(ApplicationGraph $graph): string
    {
        $lines = ['# Event Registry', ''];
        foreach ($graph->nodesByType('event') as $node) {
            $payload = $node->payload();
            $name = (string) ($payload['name'] ?? '');
            if ($name === '') {
                continue;
            }
            $emitters = array_values(array_map('strval', (array) ($payload['emitters'] ?? [])));
            $subscribers = array_values(array_map('strval', (array) ($payload['subscribers'] ?? [])));
            $lines[] = '## ' . $name;
            $lines[] = '- explorer: ' . $this->explorerLink($node->id());
            $lines[] = '- emitters: ' . implode(', ', $emitters);
            $lines[] = '- subscribers: ' . implode(', ', $subscribers);
            $lines[] = '';
        }

        return implode("\n", $lines) . "\n";
    }

    private function jobsDoc(ApplicationGraph $graph): string
    {
        $lines = ['# Job Registry', ''];
        foreach ($graph->nodesByType('job') as $node) {
            $payload = $node->payload();
            $name = (string) ($payload['name'] ?? '');
            if ($name === '') {
                continue;
            }
            $features = array_values(array_map('strval', (array) ($payload['features'] ?? [])));
            $lines[] = '## ' . $name;
            $lines[] = '- explorer: ' . $this->explorerLink($node->id());
            $lines[] = '- features: ' . implode(', ', $features);
            $lines[] = '';
        }

        return implode("\n", $lines) . "\n";
    }

    private function cachesDoc(ApplicationGraph $graph): string
    {
        $lines = ['# Cache Registry', ''];
        foreach ($graph->nodesByType('cache') as $node) {
            $payload = $node->payload();
            $key = (string) ($payload['key'] ?? '');
            if ($key === '') {
                continue;
            }
            $invalidatedBy = array_values(array_map('strval', (array) ($payload['invalidated_by'] ?? [])));
            $lines[] = '## ' . $key;
            $lines[] = '- explorer: ' . $this->explorerLink($node->id());
            $lines[] = '- invalidated_by: ' . implode(', ', $invalidatedBy);
            $lines[] = '';
        }

        return implode("\n", $lines) . "\n";
    }

    private function schemasDoc(ApplicationGraph $graph): string
    {
        $lines = ['# Schema Catalog', ''];
        foreach ($graph->nodesByType('schema') as $node) {
            $payload = $node->payload();
            $path = (string) ($payload['path'] ?? '');
            if ($path === '') {
                continue;
            }
            $role = (string) ($payload['role'] ?? '');
            $feature = (string) ($payload['feature'] ?? '');
            $notification = (string) ($payload['notification'] ?? '');
            $lines[] = '- ' . $path . ' (role=' . $role . ' feature=' . $feature . ' notification=' . $notification . ') ' . $this->explorerLink($node->id());
        }
        $lines[] = '';

        return implode("\n", $lines) . "\n";
    }

    private function explorerLink(string $nodeId): string
    {
        return '[Open in Architecture Explorer](architecture-explorer.html?node=' . rawurlencode($nodeId) . ')';
    }

    private function llmWorkflowDoc(): string
    {
        $commandPrefix = CliCommandPrefix::foundry($this->paths);
        $lines = [
            '# LLM Workflow',
            '',
            '1. inspect graph reality before edits',
            '2. edit source-of-truth files under app/features and app/definitions',
            '3. compile graph and inspect diagnostics',
            '4. inspect impact and execution plans',
            '5. verify graph, pipeline, and domain verifiers',
            '6. run phpunit',
            '',
            'Recommended commands:',
            '- ' . $commandPrefix . ' compile graph --json',
            '- ' . $commandPrefix . ' inspect graph --json',
            '- ' . $commandPrefix . ' inspect impact --file=<path> --json',
            '- ' . $commandPrefix . ' verify graph --json',
            '- ' . $commandPrefix . ' verify pipeline --json',
            '- ' . $commandPrefix . ' verify contracts --json',
            '- php vendor/bin/phpunit',
            '',
        ];

        return implode("\n", $lines);
    }

    private function apiSurfaceDoc(): string
    {
        $policy = $this->apiSurfaceRegistry->policy();
        $lines = [
            '# API Surface Policy',
            '',
            '## Classification Strategy',
            '- ' . (string) ($policy['classification_strategy'] ?? ''),
            '',
            '## Naming Rules',
        ];

        foreach ((array) ($policy['naming_rules'] ?? []) as $rule) {
            $lines[] = '- ' . (string) $rule;
        }

        $lines[] = '';
        $lines[] = '## Semver Rules';
        $pre = is_array($policy['pre_1_0'] ?? null) ? $policy['pre_1_0'] : [];
        $post = is_array($policy['post_1_0'] ?? null) ? $policy['post_1_0'] : [];
        $lines[] = '- pre-1.0 stable: ' . (string) ($pre['stable'] ?? '');
        $lines[] = '- pre-1.0 experimental: ' . (string) ($pre['experimental'] ?? '');
        $lines[] = '- pre-1.0 internal: ' . (string) ($pre['internal'] ?? '');
        $lines[] = '- post-1.0 stable: ' . (string) ($post['stable'] ?? '');
        $lines[] = '- post-1.0 experimental: ' . (string) ($post['experimental'] ?? '');
        $lines[] = '- post-1.0 internal: ' . (string) ($post['internal'] ?? '');
        $lines[] = '';
        $lines[] = '## PHP Namespace Rules';

        foreach ($this->apiSurfaceRegistry->phpNamespaceRules() as $entry) {
            $lines[] = '- ' . (string) ($entry['identifier'] ?? '')
                . ' [' . (string) ($entry['classification'] ?? '') . '/' . (string) ($entry['stability'] ?? '') . ']'
                . ': ' . (string) ($entry['summary'] ?? '');
        }

        $lines[] = '';
        $lines[] = '## Extension Hooks';
        foreach ($this->apiSurfaceRegistry->extensionHooks() as $entry) {
            $lines[] = '- ' . (string) ($entry['identifier'] ?? '')
                . ' [' . (string) ($entry['classification'] ?? '') . '/' . (string) ($entry['stability'] ?? '') . ']'
                . ': ' . (string) ($entry['summary'] ?? '');
        }

        $lines[] = '';
        $lines[] = '## Configuration And Manifest Contracts';
        foreach (array_merge($this->apiSurfaceRegistry->configurationFormats(), $this->apiSurfaceRegistry->manifestSchemas()) as $entry) {
            $lines[] = '- ' . (string) ($entry['identifier'] ?? '')
                . ' [' . (string) ($entry['classification'] ?? '') . '/' . (string) ($entry['stability'] ?? '') . ']'
                . ': ' . (string) ($entry['summary'] ?? '');
        }

        $lines[] = '';
        $lines[] = '## Generated Metadata';
        foreach ($this->apiSurfaceRegistry->generatedMetadataFormats() as $entry) {
            $lines[] = '- ' . (string) ($entry['identifier'] ?? '')
                . ' [' . (string) ($entry['classification'] ?? '') . '/' . (string) ($entry['stability'] ?? '') . ']'
                . ': ' . (string) ($entry['summary'] ?? '');
        }
        $lines[] = '';

        return implode("\n", $lines) . "\n";
    }

    private function cliReferenceDoc(): string
    {
        $help = $this->apiSurfaceRegistry->cliHelpIndex();
        $lines = [
            '# CLI Reference',
            '',
        ];

        $groups = is_array($help['commands'] ?? null) ? $help['commands'] : [];
        foreach (['stable' => 'Stable Commands', 'experimental' => 'Experimental Commands', 'internal' => 'Internal Commands'] as $key => $label) {
            $lines[] = '## ' . $label;

            foreach ((array) ($groups[$key] ?? []) as $entry) {
                if (!is_array($entry)) {
                    continue;
                }

                $lines[] = '- ' . (string) ($entry['signature'] ?? '')
                    . ' [' . (string) ($entry['stability'] ?? '') . ']'
                    . ': ' . (string) ($entry['summary'] ?? '')
                    . ' Usage: ' . (string) ($entry['usage'] ?? '');
            }

            $lines[] = '';
        }

        return implode("\n", $lines) . "\n";
    }

    private function upgradeReferenceDoc(): string
    {
        $commandPrefix = CliCommandPrefix::foundry($this->paths);
        $registry = new FrameworkDeprecationRegistry();
        $lines = [
            '# Upgrade Reference',
            '',
            '## Upgrade Check',
            '- Run `' . $commandPrefix . ' upgrade-check --json` for the default next stable target.',
            '- Run `' . $commandPrefix . ' upgrade-check --target=1.0.0 --json` to pin a specific target version.',
            '- Reports include the affected surface, why the issue matters, when the upgrade rule was introduced, and how to migrate.',
            '',
            '## Structured Deprecations',
        ];

        foreach ($registry->all() as $entry) {
            $lines[] = '### ' . $entry->title;
            $lines[] = '- introduced in: ' . $entry->introducedIn;
            $lines[] = '- removal target: ' . $entry->removalVersion;
            $lines[] = '- why it matters: ' . $entry->whyItMatters;
            $lines[] = '- migration: ' . $entry->migration;
            $lines[] = '- reference: ' . $entry->reference;
            $lines[] = '';
        }

        return implode("\n", $lines) . "\n";
    }

    /**
     * @param array<string,int> $values
     */
    private function inlineMap(array $values): string
    {
        if ($values === []) {
            return '(none)';
        }

        $pairs = [];
        foreach ($values as $key => $value) {
            $pairs[] = $key . '=' . (string) $value;
        }

        return implode(', ', $pairs);
    }

    private function toHtml(string $markdown): string
    {
        $content = (new MarkdownPageRenderer())->render($markdown);
        $html = [
            '<!doctype html>',
            '<html lang="en">',
            '<head>',
            '  <meta charset="utf-8">',
            '  <meta name="viewport" content="width=device-width, initial-scale=1">',
            '  <title>Foundry Docs</title>',
            '  <style>body{font-family:ui-monospace,Menlo,Monaco,Consolas,"Liberation Mono","Courier New",monospace;line-height:1.5;padding:24px;max-width:1000px;margin:0 auto;}h1,h2{line-height:1.25;}code{background:#f3f4f6;padding:1px 4px;border-radius:4px;}ul{padding-left:20px;}</style>',
            '</head>',
            '<body>',
            $content,
            '</body>',
            '</html>',
        ];

        return implode("\n", $html) . "\n";
    }
}
