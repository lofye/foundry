<?php
declare(strict_types=1);

namespace Foundry\Feature;

use Foundry\Http\Route;
use Foundry\Http\RouteCollection;
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

        $path = $this->paths->join('app/generated/routes.php');
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

    private function loadFeatures(): void
    {
        if ($this->features !== null) {
            return;
        }

        $path = $this->paths->join('app/generated/feature_index.php');
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
}
