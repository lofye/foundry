<?php
declare(strict_types=1);

namespace Foundry\Feature;

use Foundry\Http\Route;
use Foundry\Http\RouteCollection;
use Foundry\Pipeline\PipelineDefinitionResolver;
use Foundry\Support\FoundryError;
use Foundry\Support\Json;
use Foundry\Support\Paths;

final class FeatureLoader implements FeatureRegistry
{
    /**
     * @var array<string,FeatureDefinition>|null
     */
    private ?array $features = null;

    /**
     * @var RouteCollection|null
     */
    private ?RouteCollection $routes = null;

    /**
     * @var array<string,mixed>|null
     */
    private ?array $executionPlans = null;

    /**
     * @var array<string,mixed>|null
     */
    private ?array $pipeline = null;

    /**
     * @var array<string,mixed>|null
     */
    private ?array $guards = null;

    /**
     * @var array<string,mixed>|null
     */
    private ?array $interceptors = null;

    public function __construct(private readonly Paths $paths)
    {
    }

    #[\Override]
    public function all(): array
    {
        $this->loadFeatures();

        return $this->features ?? [];
    }

    #[\Override]
    public function has(string $feature): bool
    {
        $this->loadFeatures();

        return isset($this->features[$feature]);
    }

    #[\Override]
    public function get(string $feature): FeatureDefinition
    {
        $this->loadFeatures();

        if (!isset($this->features[$feature])) {
            throw new FoundryError('FEATURE_NOT_FOUND', 'not_found', ['feature' => $feature], 'Feature not found.');
        }

        return $this->features[$feature];
    }

    public function routes(): RouteCollection
    {
        if ($this->routes !== null) {
            return $this->routes;
        }

        $path = $this->indexPath('routes.php', 'routes_index.php');
        if (!is_file($path)) {
            $this->routes = new RouteCollection([]);

            return $this->routes;
        }

        /** @var mixed $raw */
        $raw = require $path;
        if (!is_array($raw)) {
            throw new FoundryError('ROUTE_INDEX_INVALID', 'validation', ['path' => $path], 'Route index must return an array.');
        }

        $routes = [];
        foreach ($raw as $key => $row) {
            if (!is_array($row)) {
                continue;
            }

            [$method, $uri] = explode(' ', (string) $key, 2) + ['', ''];
            $routes[] = new Route(
                method: $method,
                path: $uri,
                feature: (string) ($row['feature'] ?? ''),
                kind: (string) ($row['kind'] ?? 'http'),
                inputSchema: (string) ($row['input_schema'] ?? ''),
                outputSchema: (string) ($row['output_schema'] ?? ''),
            );
        }

        $this->routes = new RouteCollection($routes);

        return $this->routes;
    }

    public function contextManifest(string $feature): ?FeatureContextManifest
    {
        $path = $this->paths->join('app/features/' . $feature . '/context.manifest.json');
        if (!is_file($path)) {
            return null;
        }

        $json = file_get_contents($path);
        if ($json === false) {
            return null;
        }

        return FeatureContextManifest::fromArray(Json::decodeAssoc($json));
    }

    /**
     * @return array<string,mixed>
     */
    public function executionPlans(): array
    {
        if ($this->executionPlans !== null) {
            return $this->executionPlans;
        }

        $raw = $this->loadIndexArray('execution_plan_index.php', 'execution_plan_index.php');
        $byFeature = is_array($raw['by_feature'] ?? null) ? $raw['by_feature'] : [];
        $byRoute = is_array($raw['by_route'] ?? null) ? $raw['by_route'] : [];
        ksort($byFeature);
        ksort($byRoute);

        return $this->executionPlans = [
            'by_feature' => $byFeature,
            'by_route' => $byRoute,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public function pipelineDefinition(): array
    {
        if ($this->pipeline !== null) {
            return $this->pipeline;
        }

        $raw = $this->loadIndexArray('pipeline_index.php', 'pipeline_index.php');
        $order = array_values(array_map('strval', (array) ($raw['order'] ?? [])));
        if ($order === []) {
            $order = PipelineDefinitionResolver::defaultStages();
        }

        $stages = is_array($raw['stages'] ?? null) ? $raw['stages'] : [];
        $links = is_array($raw['links'] ?? null) ? $raw['links'] : [];

        return $this->pipeline = [
            'version' => (int) ($raw['version'] ?? 1),
            'order' => $order,
            'stages' => $stages,
            'links' => $links,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public function guards(): array
    {
        if ($this->guards !== null) {
            return $this->guards;
        }

        $rows = $this->loadIndexArray('guard_index.php', 'guard_index.php');
        ksort($rows);

        return $this->guards = $rows;
    }

    /**
     * @return array<string,mixed>
     */
    public function interceptors(): array
    {
        if ($this->interceptors !== null) {
            return $this->interceptors;
        }

        $rows = $this->loadIndexArray('interceptor_index.php', 'interceptor_index.php');
        if ($rows !== []) {
            uasort(
                $rows,
                static fn (array $a, array $b): int => strcmp((string) ($a['stage'] ?? ''), (string) ($b['stage'] ?? ''))
                    ?: ((int) ($a['priority'] ?? 0) <=> (int) ($b['priority'] ?? 0))
                    ?: strcmp((string) ($a['id'] ?? ''), (string) ($b['id'] ?? '')),
            );
        }

        return $this->interceptors = $rows;
    }

    private function loadFeatures(): void
    {
        if ($this->features !== null) {
            return;
        }

        $path = $this->indexPath('feature_index.php', 'feature_index.php');
        if (!is_file($path)) {
            $this->features = [];

            return;
        }

        /** @var mixed $raw */
        $raw = require $path;
        if (!is_array($raw)) {
            throw new FoundryError('FEATURE_INDEX_INVALID', 'validation', ['path' => $path], 'Feature index must return an array.');
        }

        $loaded = [];
        foreach ($raw as $name => $row) {
            if (!is_array($row)) {
                continue;
            }

            $row['feature'] = $name;
            $loaded[(string) $name] = FeatureDefinition::fromArray($row);
        }

        ksort($loaded);
        $this->features = $loaded;
    }

    private function indexPath(string $legacyFile, string $buildFile): string
    {
        $buildPath = $this->paths->join('app/.foundry/build/projections/' . $buildFile);
        if (is_file($buildPath)) {
            return $buildPath;
        }

        return $this->paths->join('app/generated/' . $legacyFile);
    }

    /**
     * @return array<string,mixed>
     */
    private function loadIndexArray(string $legacyFile, string $buildFile): array
    {
        $path = $this->indexPath($legacyFile, $buildFile);
        if (!is_file($path)) {
            return [];
        }

        /** @var mixed $raw */
        $raw = require $path;
        if (!is_array($raw)) {
            throw new FoundryError('INDEX_INVALID', 'validation', ['path' => $path], 'Generated index must return an array.');
        }

        return $raw;
    }
}
