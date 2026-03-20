<?php
declare(strict_types=1);

namespace Foundry\Documentation;

use Foundry\Compiler\ApplicationGraph;
use Foundry\Compiler\IR\GraphNode;
use Foundry\Support\ApiSurfaceRegistry;
use Foundry\Support\Paths;
use Foundry\Upgrade\FrameworkDeprecationRegistry;

final class GraphDocsGenerator
{
    public function __construct(
        private readonly Paths $paths,
        private readonly ApiSurfaceRegistry $apiSurfaceRegistry,
    )
    {
    }

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

        $docs = [
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
        usort($protected, static fn (array $a, array $b): int => strcmp((string) ($a['feature'] ?? ''), (string) ($b['feature'] ?? '')));

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
            $lines[] = '- ' . $path . ' (role=' . $role . ' feature=' . $feature . ' notification=' . $notification . ')';
        }
        $lines[] = '';

        return implode("\n", $lines) . "\n";
    }

    private function llmWorkflowDoc(): string
    {
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
            '- php vendor/bin/foundry compile graph --json',
            '- php vendor/bin/foundry inspect graph --json',
            '- php vendor/bin/foundry inspect impact --file=<path> --json',
            '- php vendor/bin/foundry verify graph --json',
            '- php vendor/bin/foundry verify pipeline --json',
            '- php vendor/bin/foundry verify contracts --json',
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
        $registry = new FrameworkDeprecationRegistry();
        $lines = [
            '# Upgrade Reference',
            '',
            '## Upgrade Check',
            '- Run `php vendor/bin/foundry upgrade-check --json` for the default next stable target.',
            '- Run `php vendor/bin/foundry upgrade-check --target=1.0.0 --json` to pin a specific target version.',
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

    private function toHtml(string $markdown): string
    {
        $lines = explode("\n", $markdown);
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
        ];

        foreach ($lines as $line) {
            $escaped = htmlspecialchars($line, ENT_QUOTES);
            if (str_starts_with($line, '# ')) {
                $html[] = '<h1>' . htmlspecialchars(substr($line, 2), ENT_QUOTES) . '</h1>';
                continue;
            }
            if (str_starts_with($line, '## ')) {
                $html[] = '<h2>' . htmlspecialchars(substr($line, 3), ENT_QUOTES) . '</h2>';
                continue;
            }
            if (str_starts_with($line, '- ')) {
                $html[] = '<p>&bull; ' . htmlspecialchars(substr($line, 2), ENT_QUOTES) . '</p>';
                continue;
            }
            if ($line === '') {
                $html[] = '<br>';
                continue;
            }
            $html[] = '<p>' . $escaped . '</p>';
        }

        $html[] = '</body>';
        $html[] = '</html>';

        return implode("\n", $html) . "\n";
    }
}
