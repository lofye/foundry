<?php
declare(strict_types=1);

namespace Foundry\Generation;

use Foundry\Support\FoundryError;
use Foundry\Support\Paths;
use Foundry\Support\Str;
use Foundry\Support\Yaml;

final class ResourceGenerator
{
    public function __construct(
        private readonly Paths $paths,
        private readonly FeatureGenerator $featureGenerator,
        private readonly FormSchemaRenderer $formRenderer,
    ) {
    }

    /**
     * @return array<string,mixed>
     */
    public function generate(string $name, string $definitionPath, bool $force = false): array
    {
        $document = Yaml::parseFile($definitionPath);
        $resource = (string) ($document['resource'] ?? $name);
        if ($resource === '') {
            $resource = $name;
        }

        $resource = Str::toSnakeCase($resource);
        if ($resource === '') {
            throw new FoundryError('RESOURCE_NAME_INVALID', 'validation', ['resource' => $name], 'Resource name is invalid.');
        }

        $canonical = $this->canonicalResourceDefinition($document, $resource);
        $singular = $this->singularize($resource);
        $operations = array_values(array_map('strval', (array) $canonical['features']));
        $featureMap = $this->featureMap($resource, $singular, $operations, (string) ($canonical['style'] ?? 'server-rendered'));

        $generatedFeatures = [];
        $generatedFiles = [];
        foreach ($operations as $operation) {
            $feature = (string) ($featureMap[$operation] ?? '');
            if ($feature === '') {
                continue;
            }

            $generatedFeatures[] = $feature;
            $definition = $this->featureDefinition(
                canonical: $canonical,
                resource: $resource,
                singular: $singular,
                operation: $operation,
                feature: $feature,
            );

            foreach ($this->featureGenerator->generateFromArray($definition, $force) as $path) {
                $generatedFiles[] = $path;
            }

            if (in_array($operation, ['create', 'update'], true)) {
                $formPath = $this->paths->join('app/features/' . $feature . '/form.partial.php');
                if (!is_file($formPath) || $force) {
                    $formFields = is_array($canonical['fields'] ?? null) ? $canonical['fields'] : [];
                    file_put_contents($formPath, $this->formRenderer->render($feature . '_form', $formFields));
                    $generatedFiles[] = $formPath;
                }
            }
        }

        foreach ($this->writeResourceDefinition($canonical, $featureMap, $force) as $path) {
            $generatedFiles[] = $path;
        }

        foreach ($this->writeListingDefinition($canonical, $resource, $force) as $path) {
            $generatedFiles[] = $path;
        }

        foreach ($this->writeResourceMigration($canonical, $resource, $force) as $path) {
            $generatedFiles[] = $path;
        }

        sort($generatedFeatures);
        sort($generatedFiles);

        return [
            'resource' => $resource,
            'features' => array_values(array_unique($generatedFeatures)),
            'files' => array_values(array_unique($generatedFiles)),
            'definition' => $this->paths->join('app/definitions/resources/' . $resource . '.resource.yaml'),
        ];
    }

    /**
     * @param array<string,mixed> $document
     * @return array<string,mixed>
     */
    private function canonicalResourceDefinition(array $document, string $resource): array
    {
        $fields = is_array($document['fields'] ?? null) ? $document['fields'] : [];
        if ($fields === []) {
            throw new FoundryError('RESOURCE_FIELDS_REQUIRED', 'validation', ['resource' => $resource], 'Resource definition must define fields.');
        }

        $canonicalFields = [];
        foreach ($fields as $field => $definition) {
            if (!is_string($field) || $field === '' || !is_array($definition)) {
                continue;
            }

            $canonicalFields[$field] = [
                'type' => (string) ($definition['type'] ?? 'string'),
                'required' => (bool) ($definition['required'] ?? false),
                'maxLength' => isset($definition['maxLength']) ? (int) $definition['maxLength'] : null,
                'unique' => (bool) ($definition['unique'] ?? false),
                'list' => (bool) ($definition['list'] ?? false),
                'form' => (string) ($definition['form'] ?? 'text'),
                'search' => (bool) ($definition['search'] ?? false),
                'filter' => (bool) ($definition['filter'] ?? false),
                'sort' => (bool) ($definition['sort'] ?? false),
                'enum' => array_values(array_map('strval', (array) ($definition['enum'] ?? []))),
            ];
        }
        ksort($canonicalFields);

        $features = array_values(array_map('strval', (array) ($document['features'] ?? ['list', 'view', 'create', 'update', 'delete'])));
        if ($features === []) {
            $features = ['list', 'view', 'create', 'update', 'delete'];
        }

        $features = array_values(array_unique(array_filter($features, static fn (string $operation): bool => in_array($operation, ['list', 'view', 'create', 'update', 'delete'], true))));

        $listing = is_array($document['listing'] ?? null) ? $document['listing'] : [];
        if ($listing === []) {
            $listing = [
                'search' => [
                    'fields' => array_values(array_keys(array_filter($canonicalFields, static fn (array $field): bool => (bool) ($field['search'] ?? false)))),
                ],
                'filters' => $this->defaultFilters($canonicalFields),
                'sort' => [
                    'allowed' => array_values(array_keys(array_filter($canonicalFields, static fn (array $field): bool => (bool) ($field['sort'] ?? false)))),
                    'default' => '-created_at',
                ],
                'pagination' => [
                    'mode' => 'page',
                    'per_page' => 25,
                ],
            ];
        }

        return [
            'version' => 1,
            'resource' => $resource,
            'style' => (string) ($document['style'] ?? 'server-rendered'),
            'model' => [
                'table' => (string) ($document['model']['table'] ?? $resource),
                'primary_key' => (string) ($document['model']['primary_key'] ?? 'id'),
            ],
            'fields' => $canonicalFields,
            'auth' => is_array($document['auth'] ?? null) ? $document['auth'] : [],
            'features' => $features,
            'listing' => $listing,
        ];
    }

    /**
     * @param array<string,array<string,mixed>> $fields
     * @return array<string,array<string,string>>
     */
    private function defaultFilters(array $fields): array
    {
        $filters = [];
        foreach ($fields as $field => $definition) {
            if (!(bool) ($definition['filter'] ?? false)) {
                continue;
            }

            $type = (string) ($definition['type'] ?? 'string');
            $filters[$field] = [
                'type' => match ($type) {
                    'datetime' => 'date',
                    'enum' => 'enum',
                    default => 'exact',
                },
            ];
        }

        ksort($filters);

        return $filters;
    }

    /**
     * @param array<int,string> $operations
     * @return array<string,string>
     */
    private function featureMap(string $resource, string $singular, array $operations, string $style = 'server-rendered'): array
    {
        $prefix = $style === 'api' ? 'api_' : '';
        $default = [
            'list' => $prefix . 'list_' . $resource,
            'view' => $prefix . 'view_' . $singular,
            'create' => $prefix . 'create_' . $singular,
            'update' => $prefix . 'update_' . $singular,
            'delete' => $prefix . 'delete_' . $singular,
        ];

        $map = [];
        foreach ($operations as $operation) {
            $map[$operation] = $default[$operation] ?? '';
        }

        ksort($map);

        return $map;
    }

    /**
     * @param array<string,mixed> $canonical
     * @return array<string,mixed>
     */
    private function featureDefinition(array $canonical, string $resource, string $singular, string $operation, string $feature): array
    {
        $style = (string) ($canonical['style'] ?? 'server-rendered');
        $isApi = $style === 'api';

        $permission = (string) ($canonical['auth'][$operation] ?? '');
        $method = match ($operation) {
            'list', 'view' => 'GET',
            'create' => 'POST',
            'update' => 'PUT',
            'delete' => 'DELETE',
            default => 'GET',
        };

        $path = match ($operation) {
            'list' => '/' . $resource,
            'view', 'update', 'delete' => '/' . $resource . '/{id}',
            'create' => '/' . $resource,
            default => '/' . $resource,
        };

        $inputFields = [];
        if (in_array($operation, ['create', 'update'], true)) {
            $inputFields = (array) ($canonical['fields'] ?? []);
        }

        if ($operation === 'list') {
            $inputFields = [
                'q' => ['type' => 'string', 'required' => false, 'form' => 'text'],
                'page' => ['type' => 'integer', 'required' => false, 'form' => 'hidden'],
                'per_page' => ['type' => 'integer', 'required' => false, 'form' => 'hidden'],
                'sort' => ['type' => 'string', 'required' => false, 'form' => 'text'],
            ];
        }

        $outputFields = $isApi
            ? [
                'data' => ['type' => 'object', 'required' => true],
                'meta' => ['type' => 'object', 'required' => false],
                'error' => ['type' => 'object', 'required' => false],
            ]
            : [
                'status' => ['type' => 'string', 'required' => true],
                'resource' => ['type' => 'string', 'required' => false],
                'operation' => ['type' => 'string', 'required' => true],
            ];

        $queries = [
            $operation . '_' . $singular,
        ];
        if ($operation === 'list') {
            $queries = ['list_' . $resource];
        }

        return [
            'feature' => $feature,
            'kind' => 'http',
            'description' => sprintf('Generated %s feature for %s resource.', $operation, $resource),
            'route' => [
                'method' => $method,
                'path' => $isApi ? '/api' . $path : $path,
            ],
            'input' => ['fields' => $inputFields],
            'output' => ['fields' => $outputFields],
            'auth' => [
                'required' => true,
                'public' => false,
                'strategies' => [$isApi ? 'bearer' : 'session'],
                'permissions' => $permission !== '' ? [$permission] : [],
            ],
            'csrf' => [
                'required' => !$isApi && in_array($method, ['POST', 'PUT', 'PATCH', 'DELETE'], true),
            ],
            'database' => [
                'reads' => $operation === 'create' ? [] : [$canonical['model']['table']],
                'writes' => in_array($operation, ['create', 'update', 'delete'], true) ? [$canonical['model']['table']] : [],
                'transactions' => in_array($operation, ['create', 'update', 'delete'], true) ? 'required' : 'optional',
                'queries' => $queries,
            ],
            'cache' => [
                'reads' => $operation === 'list' ? [$resource . ':list'] : [],
                'writes' => [],
                'invalidate' => in_array($operation, ['create', 'update', 'delete'], true)
                    ? [$resource . ':list', $resource . ':detail']
                    : [],
            ],
            'events' => [
                'emit' => in_array($operation, ['create', 'update', 'delete'], true)
                    ? [$singular . '.' . $operation . 'd']
                    : [],
                'subscribe' => [],
            ],
            'jobs' => [
                'dispatch' => in_array($operation, ['create', 'update'], true)
                    ? ['index_' . $resource . '_search']
                    : [],
            ],
            'rate_limit' => [
                'strategy' => 'user',
                'bucket' => $resource . '_' . $operation,
                'cost' => 1,
            ],
            'tests' => [
                'required' => ['contract', 'feature', 'auth', 'integration'],
            ],
            'resource' => [
                'name' => $resource,
                'operation' => $operation,
                'primary_key' => (string) ($canonical['model']['primary_key'] ?? 'id'),
            ],
            'listing' => [
                'definition' => 'app/definitions/listing/' . $resource . '.list.yaml',
            ],
            'ui' => [
                'style' => $style,
                'api' => $isApi ? [
                    'response_envelope' => 'data',
                    'error_envelope' => 'error',
                    'content_type' => 'application/json',
                ] : [],
                'form' => [
                    'partial' => in_array($operation, ['create', 'update'], true)
                        ? 'app/features/' . $feature . '/form.partial.php'
                        : null,
                ],
            ],
        ];
    }

    /**
     * @param array<string,mixed> $canonical
     * @param array<string,string> $featureMap
     * @return array<int,string>
     */
    private function writeResourceDefinition(array $canonical, array $featureMap, bool $force): array
    {
        $dir = $this->paths->join('app/definitions/resources');
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }

        $resource = (string) $canonical['resource'];
        $path = $dir . '/' . $resource . '.resource.yaml';
        if (is_file($path) && !$force) {
            throw new FoundryError('RESOURCE_DEFINITION_EXISTS', 'io', ['path' => $path], 'Resource definition already exists. Use --force to overwrite.');
        }

        $document = $canonical;
        $document['feature_names'] = $featureMap;
        file_put_contents($path, Yaml::dump($document));

        return [$path];
    }

    /**
     * @param array<string,mixed> $canonical
     * @return array<int,string>
     */
    private function writeListingDefinition(array $canonical, string $resource, bool $force): array
    {
        $dir = $this->paths->join('app/definitions/listing');
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }

        $path = $dir . '/' . $resource . '.list.yaml';
        if (is_file($path) && !$force) {
            return [];
        }

        $listing = is_array($canonical['listing'] ?? null) ? $canonical['listing'] : [];
        $document = [
            'version' => 1,
            'resource' => $resource,
            'search' => is_array($listing['search'] ?? null) ? $listing['search'] : ['fields' => []],
            'filters' => is_array($listing['filters'] ?? null) ? $listing['filters'] : [],
            'sort' => is_array($listing['sort'] ?? null) ? $listing['sort'] : ['allowed' => [], 'default' => '-created_at'],
            'pagination' => is_array($listing['pagination'] ?? null) ? $listing['pagination'] : ['mode' => 'page', 'per_page' => 25],
        ];

        file_put_contents($path, Yaml::dump($document));

        return [$path];
    }

    /**
     * @param array<string,mixed> $canonical
     * @return array<int,string>
     */
    private function writeResourceMigration(array $canonical, string $resource, bool $force): array
    {
        $dir = $this->paths->join('database/migrations');
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }

        $path = $dir . '/0020_create_' . $resource . '.sql';
        if (is_file($path) && !$force) {
            return [];
        }

        $table = (string) ($canonical['model']['table'] ?? $resource);
        $fields = is_array($canonical['fields'] ?? null) ? $canonical['fields'] : [];

        $lines = [
            'CREATE TABLE IF NOT EXISTS ' . $table . ' (',
            '    id INTEGER PRIMARY KEY AUTOINCREMENT,',
        ];

        $uniqueFields = [];
        foreach ($fields as $name => $definition) {
            if (!is_string($name) || $name === '' || !is_array($definition)) {
                continue;
            }

            if ($name === 'id') {
                continue;
            }

            $sqlType = $this->sqlType((string) ($definition['type'] ?? 'string'));
            $nullable = (bool) ($definition['required'] ?? false) ? 'NOT NULL' : 'NULL';
            $lines[] = '    ' . $name . ' ' . $sqlType . ' ' . $nullable . ',';

            if ((bool) ($definition['unique'] ?? false)) {
                $uniqueFields[] = $name;
            }
        }

        $lines[] = '    created_at TEXT NOT NULL,';
        $lines[] = '    updated_at TEXT NOT NULL';
        $lines[] = ');';

        foreach ($uniqueFields as $field) {
            $lines[] = 'CREATE UNIQUE INDEX IF NOT EXISTS idx_' . $table . '_' . $field . ' ON ' . $table . '(' . $field . ');';
        }

        file_put_contents($path, implode("\n", $lines) . "\n");

        return [$path];
    }

    private function sqlType(string $type): string
    {
        return match ($type) {
            'integer', 'boolean' => 'INTEGER',
            'number' => 'REAL',
            default => 'TEXT',
        };
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
