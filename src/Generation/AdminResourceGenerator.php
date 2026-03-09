<?php
declare(strict_types=1);

namespace Foundry\Generation;

use Foundry\Support\FoundryError;
use Foundry\Support\Paths;
use Foundry\Support\Str;
use Foundry\Support\Yaml;

final class AdminResourceGenerator
{
    public function __construct(
        private readonly Paths $paths,
        private readonly FeatureGenerator $featureGenerator,
    ) {
    }

    /**
     * @return array<string,mixed>
     */
    public function generate(string $name, ?string $specPath = null, bool $force = false): array
    {
        $resource = Str::toSnakeCase($name);
        if ($resource === '') {
            throw new FoundryError('ADMIN_RESOURCE_NAME_INVALID', 'validation', ['resource' => $name], 'Admin resource name is invalid.');
        }

        $spec = $this->resolveSpec($resource, $specPath);
        $singular = $this->singularize($resource);

        $columns = array_values(array_map('strval', (array) ($spec['table']['columns'] ?? [])));
        $filters = array_values(array_map('strval', (array) ($spec['filters'] ?? [])));
        $bulkActions = array_values(array_map('strval', (array) ($spec['bulk_actions'] ?? [])));
        $rowActions = array_values(array_map('strval', (array) ($spec['row_actions'] ?? ['edit', 'delete'])));

        $featureSpecs = [
            $this->adminFeatureSpec($resource, 'list', 'admin_list_' . $resource, 'GET', '/admin/' . $resource, $columns, $filters),
            $this->adminFeatureSpec($resource, 'view', 'admin_view_' . $singular, 'GET', '/admin/' . $resource . '/{id}', $columns, $filters),
            $this->adminFeatureSpec($resource, 'update', 'admin_update_' . $singular, 'POST', '/admin/' . $resource . '/{id}', $columns, $filters),
            $this->adminFeatureSpec($resource, 'delete', 'admin_delete_' . $singular, 'POST', '/admin/' . $resource . '/{id}/delete', $columns, $filters),
        ];

        if ($bulkActions !== []) {
            $featureSpecs[] = $this->adminFeatureSpec($resource, 'bulk', 'admin_bulk_update_' . $resource, 'POST', '/admin/' . $resource . '/bulk', $columns, $filters);
        }

        $generatedFeatures = [];
        $generatedFiles = [];
        foreach ($featureSpecs as $featureSpec) {
            $generatedFeatures[] = (string) $featureSpec['feature'];
            foreach ($this->featureGenerator->generateFromArray($featureSpec, $force) as $path) {
                $generatedFiles[] = $path;
            }
        }

        foreach ($this->writeSpec($resource, $columns, $filters, $bulkActions, $rowActions, $force) as $path) {
            $generatedFiles[] = $path;
        }

        sort($generatedFeatures);
        sort($generatedFiles);

        return [
            'resource' => $resource,
            'features' => array_values(array_unique($generatedFeatures)),
            'files' => array_values(array_unique($generatedFiles)),
            'spec' => $this->paths->join('app/specs/admin/' . $resource . '.admin.yaml'),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function resolveSpec(string $resource, ?string $specPath): array
    {
        if ($specPath !== null && $specPath !== '') {
            return Yaml::parseFile($specPath);
        }

        $resourceSpecPath = $this->paths->join('app/specs/resources/' . $resource . '.resource.yaml');
        if (!is_file($resourceSpecPath)) {
            return [
                'resource' => $resource,
                'table' => ['columns' => ['id', 'created_at']],
                'filters' => [],
                'bulk_actions' => ['delete'],
                'row_actions' => ['edit', 'delete'],
            ];
        }

        $resourceSpec = Yaml::parseFile($resourceSpecPath);
        $fields = is_array($resourceSpec['fields'] ?? null) ? $resourceSpec['fields'] : [];
        $columns = [];
        $filters = [];
        foreach ($fields as $field => $definition) {
            if (!is_string($field) || $field === '' || !is_array($definition)) {
                continue;
            }
            if ((bool) ($definition['list'] ?? false)) {
                $columns[] = $field;
            }
            if ((bool) ($definition['filter'] ?? false)) {
                $filters[] = $field;
            }
        }

        sort($columns);
        sort($filters);

        return [
            'resource' => $resource,
            'table' => ['columns' => $columns],
            'filters' => $filters,
            'bulk_actions' => ['delete'],
            'row_actions' => ['edit', 'delete'],
        ];
    }

    /**
     * @param array<int,string> $columns
     * @param array<int,string> $filters
     * @return array<string,mixed>
     */
    private function adminFeatureSpec(string $resource, string $operation, string $feature, string $method, string $path, array $columns, array $filters): array
    {
        return [
            'feature' => $feature,
            'kind' => 'http',
            'description' => sprintf('Generated admin %s feature for %s.', $operation, $resource),
            'route' => ['method' => $method, 'path' => $path],
            'input' => ['fields' => [
                'id' => ['type' => 'integer', 'required' => false, 'form' => 'hidden'],
                'search' => ['type' => 'string', 'required' => false, 'form' => 'text'],
                'page' => ['type' => 'integer', 'required' => false, 'form' => 'hidden'],
            ]],
            'output' => ['fields' => [
                'status' => ['type' => 'string', 'required' => true],
                'resource' => ['type' => 'string', 'required' => true],
            ]],
            'auth' => [
                'required' => true,
                'strategies' => ['session'],
                'permissions' => ['admin.' . $resource . '.' . $operation],
            ],
            'csrf' => ['required' => $method !== 'GET'],
            'database' => [
                'reads' => [$resource],
                'writes' => in_array($operation, ['update', 'delete', 'bulk'], true) ? [$resource] : [],
                'transactions' => in_array($operation, ['update', 'delete', 'bulk'], true) ? 'required' : 'optional',
                'queries' => ['admin_' . $operation . '_' . $resource],
            ],
            'cache' => [
                'reads' => [$resource . ':list'],
                'writes' => [],
                'invalidate' => in_array($operation, ['update', 'delete', 'bulk'], true) ? [$resource . ':list', $resource . ':detail'] : [],
            ],
            'events' => ['emit' => [], 'subscribe' => []],
            'jobs' => ['dispatch' => []],
            'rate_limit' => [
                'strategy' => 'user',
                'bucket' => 'admin_' . $resource . '_' . $operation,
                'cost' => 1,
            ],
            'tests' => ['required' => ['contract', 'feature', 'auth', 'integration']],
            'resource' => [
                'name' => $resource,
                'operation' => 'admin_' . $operation,
                'admin' => true,
            ],
            'listing' => [
                'spec' => 'app/specs/listing/' . $resource . '.list.yaml',
                'columns' => $columns,
                'filters' => $filters,
            ],
            'ui' => [
                'style' => 'server-rendered',
                'table' => [
                    'columns' => $columns,
                    'filters' => $filters,
                ],
            ],
        ];
    }

    /**
     * @param array<int,string> $columns
     * @param array<int,string> $filters
     * @param array<int,string> $bulkActions
     * @param array<int,string> $rowActions
     * @return array<int,string>
     */
    private function writeSpec(string $resource, array $columns, array $filters, array $bulkActions, array $rowActions, bool $force): array
    {
        $dir = $this->paths->join('app/specs/admin');
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }

        $path = $dir . '/' . $resource . '.admin.yaml';
        if (is_file($path) && !$force) {
            throw new FoundryError('ADMIN_SPEC_EXISTS', 'io', ['path' => $path], 'Admin spec already exists. Use --force to overwrite.');
        }

        $document = [
            'version' => 1,
            'resource' => $resource,
            'table' => ['columns' => $columns],
            'filters' => $filters,
            'bulk_actions' => $bulkActions,
            'row_actions' => $rowActions,
        ];

        file_put_contents($path, Yaml::dump($document));

        return [$path];
    }

    private function singularize(string $resource): string
    {
        if (str_ends_with($resource, 'ies') && strlen($resource) > 3) {
            return substr($resource, 0, -3) . 'y';
        }

        if (str_ends_with($resource, 's') && strlen($resource) > 1) {
            return substr($resource, 0, -1);
        }

        return $resource;
    }
}
