<?php
declare(strict_types=1);

namespace Foundry\Explain;

use Foundry\Compiler\BuildLayout;
use Foundry\Support\ApiSurfaceRegistry;
use Foundry\Support\Json;
use Foundry\Support\Paths;

final class ExplainArtifactCatalog
{
    /**
     * @var array<string,array<string,mixed>>
     */
    private array $projectionCache = [];

    /**
     * @var array<string,mixed>|null
     */
    private ?array $diagnosticsCache = null;

    /**
     * @var array<int,array<string,mixed>>|null
     */
    private ?array $docsCache = null;

    /**
     * @param array<int,array<string,mixed>> $extensionRows
     */
    public function __construct(
        private readonly BuildLayout $layout,
        private readonly Paths $paths,
        private readonly ApiSurfaceRegistry $apiSurfaceRegistry,
        private readonly array $extensionRows = [],
    ) {
    }

    /**
     * @return array<string,mixed>
     */
    public function featureIndex(): array
    {
        return $this->projection('feature_index.php');
    }

    /**
     * @return array<string,mixed>
     */
    public function routeIndex(): array
    {
        return $this->projection('routes_index.php');
    }

    /**
     * @return array<string,mixed>
     */
    public function eventIndex(): array
    {
        return $this->projection('event_index.php');
    }

    /**
     * @return array<string,mixed>
     */
    public function workflowIndex(): array
    {
        return $this->projection('workflow_index.php');
    }

    /**
     * @return array<string,mixed>
     */
    public function jobIndex(): array
    {
        return $this->projection('job_index.php');
    }

    /**
     * @return array<string,mixed>
     */
    public function schemaIndex(): array
    {
        return $this->projection('schema_index.php');
    }

    /**
     * @return array<string,mixed>
     */
    public function permissionIndex(): array
    {
        return $this->projection('permission_index.php');
    }

    /**
     * @return array<string,mixed>
     */
    public function executionPlanIndex(): array
    {
        return $this->projection('execution_plan_index.php');
    }

    /**
     * @return array<string,mixed>
     */
    public function guardIndex(): array
    {
        return $this->projection('guard_index.php');
    }

    /**
     * @return array<string,mixed>
     */
    public function pipelineIndex(): array
    {
        return $this->projection('pipeline_index.php');
    }

    /**
     * @return array<string,mixed>
     */
    public function interceptorIndex(): array
    {
        return $this->projection('interceptor_index.php');
    }

    /**
     * @return array<string,mixed>
     */
    public function diagnosticsReport(): array
    {
        if ($this->diagnosticsCache !== null) {
            return $this->diagnosticsCache;
        }

        $path = $this->layout->diagnosticsPath();
        if (!is_file($path)) {
            return $this->diagnosticsCache = [
                'summary' => ['error' => 0, 'warning' => 0, 'info' => 0, 'total' => 0],
                'diagnostics' => [],
            ];
        }

        $json = file_get_contents($path);
        if ($json === false) {
            return $this->diagnosticsCache = [
                'summary' => ['error' => 0, 'warning' => 0, 'info' => 0, 'total' => 0],
                'diagnostics' => [],
            ];
        }

        /** @var array<string,mixed> $decoded */
        $decoded = Json::decodeAssoc($json);

        return $this->diagnosticsCache = $decoded;
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function cliCommands(): array
    {
        return $this->apiSurfaceRegistry->cliCommands();
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function extensions(): array
    {
        return $this->extensionRows;
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function docsPages(): array
    {
        if ($this->docsCache !== null) {
            return $this->docsCache;
        }

        $rows = [];
        foreach ($this->sourceDocsCatalog() as $row) {
            $path = $this->paths->join((string) $row['path']);
            if (!is_file($path)) {
                continue;
            }

            $rows[] = $row;
        }

        foreach ($this->generatedDocsCatalog() as $row) {
            $path = $this->paths->join((string) $row['path']);
            if (!is_file($path)) {
                continue;
            }

            $rows[] = $row;
        }

        usort(
            $rows,
            static fn (array $left, array $right): int => strcmp((string) ($left['title'] ?? ''), (string) ($right['title'] ?? ''))
                ?: strcmp((string) ($left['path'] ?? ''), (string) ($right['path'] ?? ''))
                ?: strcmp((string) ($left['id'] ?? ''), (string) ($right['id'] ?? '')),
        );

        return $this->docsCache = $rows;
    }

    /**
     * @return array<string,mixed>
     */
    public function projection(string $file): array
    {
        if (array_key_exists($file, $this->projectionCache)) {
            return $this->projectionCache[$file];
        }

        $path = $this->layout->projectionPath($file);
        if (!is_file($path)) {
            return $this->projectionCache[$file] = [];
        }

        /** @var mixed $raw */
        $raw = require $path;

        return $this->projectionCache[$file] = is_array($raw) ? $raw : [];
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function sourceDocsCatalog(): array
    {
        return [
            [
                'id' => 'architecture-tools',
                'title' => 'Architecture Tools',
                'path' => 'docs/architecture-tools.md',
                'source' => 'docs',
                'subjects' => ['feature', 'route', 'command', 'pipeline_stage', 'workflow', 'event', 'job', 'schema', 'extension'],
                'commands' => ['explain', 'doctor', 'graph inspect', 'graph visualize', 'export graph', 'prompt', 'diff', 'trace', 'generate <prompt>'],
            ],
            [
                'id' => 'how-it-works',
                'title' => 'How It Works',
                'path' => 'docs/how-it-works.md',
                'source' => 'docs',
                'subjects' => ['feature', 'route', 'workflow', 'event', 'job', 'schema', 'extension'],
            ],
            [
                'id' => 'execution-pipeline',
                'title' => 'Execution Pipeline',
                'path' => 'docs/execution-pipeline.md',
                'source' => 'docs',
                'subjects' => ['feature', 'route', 'pipeline_stage', 'workflow', 'event'],
            ],
            [
                'id' => 'reference',
                'title' => 'Reference',
                'path' => 'docs/reference.md',
                'source' => 'docs',
                'subjects' => ['feature', 'route', 'command', 'pipeline_stage', 'workflow', 'event', 'job', 'schema', 'extension'],
            ],
            [
                'id' => 'extension-author-guide',
                'title' => 'Extension Author Guide',
                'path' => 'docs/extension-author-guide.md',
                'source' => 'docs',
                'subjects' => ['extension', 'command'],
            ],
            [
                'id' => 'extensions-and-migrations',
                'title' => 'Extensions And Migrations',
                'path' => 'docs/extensions-and-migrations.md',
                'source' => 'docs',
                'subjects' => ['extension'],
            ],
            [
                'id' => 'public-api-policy',
                'title' => 'Public API Policy',
                'path' => 'docs/public-api-policy.md',
                'source' => 'docs',
                'subjects' => ['command', 'extension'],
            ],
        ];
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function generatedDocsCatalog(): array
    {
        return [
            [
                'id' => 'graph-overview',
                'title' => 'Graph Overview',
                'path' => 'docs/generated/graph-overview.md',
                'source' => 'generated',
                'subjects' => ['feature', 'route', 'pipeline_stage', 'workflow', 'event', 'job', 'schema', 'extension'],
            ],
            [
                'id' => 'features',
                'title' => 'Feature Catalog',
                'path' => 'docs/generated/features.md',
                'source' => 'generated',
                'subjects' => ['feature'],
            ],
            [
                'id' => 'routes',
                'title' => 'Route Catalog',
                'path' => 'docs/generated/routes.md',
                'source' => 'generated',
                'subjects' => ['route'],
            ],
            [
                'id' => 'events',
                'title' => 'Event Registry',
                'path' => 'docs/generated/events.md',
                'source' => 'generated',
                'subjects' => ['event'],
            ],
            [
                'id' => 'jobs',
                'title' => 'Job Registry',
                'path' => 'docs/generated/jobs.md',
                'source' => 'generated',
                'subjects' => ['job'],
            ],
            [
                'id' => 'schemas',
                'title' => 'Schema Catalog',
                'path' => 'docs/generated/schemas.md',
                'source' => 'generated',
                'subjects' => ['schema'],
            ],
            [
                'id' => 'cli-reference',
                'title' => 'CLI Reference',
                'path' => 'docs/generated/cli-reference.md',
                'source' => 'generated',
                'subjects' => ['command'],
                'commands' => ['explain', 'doctor', 'graph inspect', 'graph visualize', 'export graph', 'prompt', 'diff', 'trace', 'generate <prompt>'],
            ],
            [
                'id' => 'api-surface',
                'title' => 'API Surface Policy',
                'path' => 'docs/generated/api-surface.md',
                'source' => 'generated',
                'subjects' => ['command', 'extension'],
            ],
        ];
    }
}
