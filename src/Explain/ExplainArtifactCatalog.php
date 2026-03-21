<?php
declare(strict_types=1);

namespace Foundry\Explain;

use Foundry\Compiler\BuildLayout;
use Foundry\Support\ApiSurfaceRegistry;
use Foundry\Support\Json;

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
     * @param array<int,array<string,mixed>> $extensionRows
     */
    public function __construct(
        private readonly BuildLayout $layout,
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
}
