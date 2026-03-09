<?php
declare(strict_types=1);

namespace Foundry\Compiler\Passes;

use Foundry\Compiler\CompilationState;
use Foundry\Compiler\CompilerPass;
use Foundry\Compiler\GraphEdge;
use Foundry\Compiler\IR\AdminResourceNode;
use Foundry\Compiler\IR\FormDefinitionNode;
use Foundry\Compiler\IR\ListingConfigNode;
use Foundry\Compiler\IR\ResourceNode;
use Foundry\Compiler\IR\StarterKitNode;
use Foundry\Compiler\IR\UploadProfileNode;

final class PhaseOneSpecPass implements CompilerPass
{
    /**
     * @var array<int,string>
     */
    private array $allowedFieldTypes = [
        'string',
        'text',
        'email',
        'password',
        'datetime',
        'boolean',
        'integer',
        'number',
        'file',
        'enum',
        'array',
    ];

    /**
     * @var array<int,string>
     */
    private array $allowedCrudOperations = ['list', 'view', 'create', 'update', 'delete'];

    public function name(): string
    {
        return 'phase1_specs';
    }

    public function run(CompilationState $state): void
    {
        $specs = $state->discoveredSpecs;
        if ($specs === []) {
            return;
        }

        $resourceFields = [];
        $resourceFeatureMaps = [];

        foreach ($this->sortedRows((array) ($specs['resource'] ?? [])) as $row) {
            $document = is_array($row['document'] ?? null) ? $row['document'] : [];
            $sourcePath = (string) ($row['path'] ?? '');
            $resource = (string) ($document['resource'] ?? ($row['name'] ?? ''));
            if ($resource === '') {
                continue;
            }

            $version = (int) ($document['version'] ?? 1);
            if ($version !== 1) {
                $state->diagnostics->error(
                    code: 'FDY2201_RESOURCE_SPEC_VERSION_UNSUPPORTED',
                    category: 'migrations',
                    message: sprintf('Unsupported resource spec version %d for %s.', $version, $resource),
                    nodeId: 'resource:' . $resource,
                    sourcePath: $sourcePath,
                    suggestedFix: 'Set version: 1 or run spec migrations.',
                    pass: $this->name(),
                );
            }

            $fields = $this->normalizeFields((array) ($document['fields'] ?? []), $state, $resource, $sourcePath);
            $resourceFields[$resource] = array_keys($fields);
            sort($resourceFields[$resource]);

            $operations = $this->normalizeOperations((array) ($document['features'] ?? []));
            $featureMap = $this->crudFeatureMap(
                resource: $resource,
                operations: $operations,
                overrides: is_array($document['feature_names'] ?? null) ? $document['feature_names'] : [],
            );
            $resourceFeatureMaps[$resource] = $featureMap;

            $resourceId = 'resource:' . $resource;
            $state->graph->addNode(new ResourceNode(
                id: $resourceId,
                sourcePath: $sourcePath,
                payload: [
                    'resource' => $resource,
                    'version' => $version,
                    'style' => (string) ($document['style'] ?? 'server-rendered'),
                    'model' => is_array($document['model'] ?? null) ? $document['model'] : [],
                    'fields' => $fields,
                    'operations' => $operations,
                    'feature_map' => $featureMap,
                    'auth' => is_array($document['auth'] ?? null) ? $document['auth'] : [],
                ],
                sourceRegion: ['line_start' => 1, 'line_end' => null],
                graphCompatibility: [1],
            ));

            foreach ($featureMap as $operation => $feature) {
                if (!$state->graph->hasNode('feature:' . $feature)) {
                    $state->diagnostics->warning(
                        code: 'FDY2202_RESOURCE_FEATURE_MISSING',
                        category: 'validation',
                        message: sprintf('Resource %s expects %s feature %s but it was not found.', $resource, $operation, $feature),
                        nodeId: $resourceId,
                        sourcePath: $sourcePath,
                        suggestedFix: 'Regenerate the resource features or update feature_names.',
                        pass: $this->name(),
                    );
                    continue;
                }

                $state->graph->addEdge(GraphEdge::make('resource_to_feature', $resourceId, 'feature:' . $feature, ['operation' => $operation]));
            }

            foreach (['create', 'update'] as $intent) {
                if (!isset($featureMap[$intent])) {
                    continue;
                }

                $formId = 'form_definition:' . $resource . ':' . $intent;
                $state->graph->addNode(new FormDefinitionNode(
                    id: $formId,
                    sourcePath: $sourcePath,
                    payload: [
                        'resource' => $resource,
                        'intent' => $intent,
                        'feature' => $featureMap[$intent],
                        'fields' => $this->formFields($fields),
                    ],
                    sourceRegion: ['line_start' => 1, 'line_end' => null],
                    graphCompatibility: [1],
                ));
                $state->graph->addEdge(GraphEdge::make('resource_to_form_definition', $resourceId, $formId, ['intent' => $intent]));

                if ($state->graph->hasNode('feature:' . $featureMap[$intent])) {
                    $state->graph->addEdge(GraphEdge::make('form_definition_to_feature', $formId, 'feature:' . $featureMap[$intent]));
                }
            }
        }

        foreach ($this->sortedRows((array) ($specs['listing_config'] ?? [])) as $row) {
            $document = is_array($row['document'] ?? null) ? $row['document'] : [];
            $sourcePath = (string) ($row['path'] ?? '');
            $resource = (string) ($document['resource'] ?? ($row['name'] ?? ''));
            if ($resource === '') {
                continue;
            }

            $version = (int) ($document['version'] ?? 1);
            if ($version !== 1) {
                $state->diagnostics->error(
                    code: 'FDY2203_LISTING_SPEC_VERSION_UNSUPPORTED',
                    category: 'migrations',
                    message: sprintf('Unsupported listing spec version %d for %s.', $version, $resource),
                    nodeId: 'listing_config:' . $resource,
                    sourcePath: $sourcePath,
                    pass: $this->name(),
                );
            }

            $resourceFieldSet = array_flip($resourceFields[$resource] ?? []);
            $searchFields = $this->sortedStrings((array) ($document['search']['fields'] ?? []));
            $filterMap = is_array($document['filters'] ?? null) ? $document['filters'] : [];
            $sortAllowed = $this->sortedStrings((array) ($document['sort']['allowed'] ?? []));

            foreach ($searchFields as $field) {
                if (!isset($resourceFieldSet[$field])) {
                    $state->diagnostics->error(
                        code: 'FDY2204_LISTING_SEARCH_FIELD_INVALID',
                        category: 'validation',
                        message: sprintf('Listing config for %s references unknown search field %s.', $resource, $field),
                        nodeId: 'listing_config:' . $resource,
                        sourcePath: $sourcePath,
                        pass: $this->name(),
                    );
                }
            }

            foreach ($sortAllowed as $field) {
                if (!isset($resourceFieldSet[$field])) {
                    $state->diagnostics->error(
                        code: 'FDY2205_LISTING_SORT_FIELD_INVALID',
                        category: 'validation',
                        message: sprintf('Listing config for %s references unknown sort field %s.', $resource, $field),
                        nodeId: 'listing_config:' . $resource,
                        sourcePath: $sourcePath,
                        pass: $this->name(),
                    );
                }
            }

            foreach (array_keys($filterMap) as $field) {
                if (!is_string($field) || $field === '') {
                    continue;
                }
                if (!isset($resourceFieldSet[$field])) {
                    $state->diagnostics->error(
                        code: 'FDY2206_LISTING_FILTER_FIELD_INVALID',
                        category: 'validation',
                        message: sprintf('Listing config for %s references unknown filter field %s.', $resource, $field),
                        nodeId: 'listing_config:' . $resource,
                        sourcePath: $sourcePath,
                        pass: $this->name(),
                    );
                }
            }

            $nodeId = 'listing_config:' . $resource;
            $state->graph->addNode(new ListingConfigNode(
                id: $nodeId,
                sourcePath: $sourcePath,
                payload: [
                    'resource' => $resource,
                    'version' => $version,
                    'search' => ['fields' => $searchFields],
                    'filters' => $filterMap,
                    'sort' => [
                        'allowed' => $sortAllowed,
                        'default' => (string) ($document['sort']['default'] ?? ''),
                    ],
                    'pagination' => is_array($document['pagination'] ?? null) ? $document['pagination'] : [],
                ],
                sourceRegion: ['line_start' => 1, 'line_end' => null],
                graphCompatibility: [1],
            ));

            if ($state->graph->hasNode('resource:' . $resource)) {
                $state->graph->addEdge(GraphEdge::make('resource_to_listing_config', 'resource:' . $resource, $nodeId));
            }

            $featureMap = $resourceFeatureMaps[$resource] ?? [];
            foreach (['list'] as $operation) {
                $feature = (string) ($featureMap[$operation] ?? '');
                if ($feature !== '' && $state->graph->hasNode('feature:' . $feature)) {
                    $state->graph->addEdge(GraphEdge::make('listing_config_to_feature', $nodeId, 'feature:' . $feature, ['operation' => $operation]));
                }
            }

            $adminListFeature = 'admin_list_' . $resource;
            if ($state->graph->hasNode('feature:' . $adminListFeature)) {
                $state->graph->addEdge(GraphEdge::make('listing_config_to_feature', $nodeId, 'feature:' . $adminListFeature, ['operation' => 'admin_list']));
            }
        }

        foreach ($this->sortedRows((array) ($specs['admin_resource'] ?? [])) as $row) {
            $document = is_array($row['document'] ?? null) ? $row['document'] : [];
            $sourcePath = (string) ($row['path'] ?? '');
            $resource = (string) ($document['resource'] ?? ($row['name'] ?? ''));
            if ($resource === '') {
                continue;
            }

            $version = (int) ($document['version'] ?? 1);
            $columns = $this->sortedStrings((array) ($document['table']['columns'] ?? []));
            $filters = $this->sortedStrings((array) ($document['filters'] ?? []));
            $bulkActions = $this->sortedStrings((array) ($document['bulk_actions'] ?? []));
            $rowActions = $this->sortedStrings((array) ($document['row_actions'] ?? []));

            $resourceFieldSet = array_flip($resourceFields[$resource] ?? []);
            foreach (array_merge($columns, $filters) as $field) {
                if (!isset($resourceFieldSet[$field])) {
                    $state->diagnostics->error(
                        code: 'FDY2207_ADMIN_FIELD_INVALID',
                        category: 'validation',
                        message: sprintf('Admin resource %s references unknown field %s.', $resource, $field),
                        nodeId: 'admin_resource:' . $resource,
                        sourcePath: $sourcePath,
                        pass: $this->name(),
                    );
                }
            }

            $featureMap = [
                'list' => 'admin_list_' . $resource,
                'view' => 'admin_view_' . $this->singularize($resource),
                'update' => 'admin_update_' . $this->singularize($resource),
                'delete' => 'admin_delete_' . $this->singularize($resource),
                'bulk' => 'admin_bulk_update_' . $resource,
            ];

            $nodeId = 'admin_resource:' . $resource;
            $state->graph->addNode(new AdminResourceNode(
                id: $nodeId,
                sourcePath: $sourcePath,
                payload: [
                    'resource' => $resource,
                    'version' => $version,
                    'columns' => $columns,
                    'filters' => $filters,
                    'bulk_actions' => $bulkActions,
                    'row_actions' => $rowActions,
                    'feature_map' => $featureMap,
                ],
                sourceRegion: ['line_start' => 1, 'line_end' => null],
                graphCompatibility: [1],
            ));

            if ($state->graph->hasNode('resource:' . $resource)) {
                $state->graph->addEdge(GraphEdge::make('admin_resource_to_resource', $nodeId, 'resource:' . $resource));
            }

            foreach ($featureMap as $operation => $feature) {
                if ($operation === 'bulk' && $bulkActions === []) {
                    continue;
                }

                if (!$state->graph->hasNode('feature:' . $feature)) {
                    $state->diagnostics->warning(
                        code: 'FDY2208_ADMIN_FEATURE_MISSING',
                        category: 'validation',
                        message: sprintf('Admin resource %s expects %s feature %s but it was not found.', $resource, $operation, $feature),
                        nodeId: $nodeId,
                        sourcePath: $sourcePath,
                        pass: $this->name(),
                    );
                    continue;
                }

                $state->graph->addEdge(GraphEdge::make('admin_resource_to_feature', $nodeId, 'feature:' . $feature, ['operation' => $operation]));
            }
        }

        foreach ($this->sortedRows((array) ($specs['upload_profile'] ?? [])) as $row) {
            $document = is_array($row['document'] ?? null) ? $row['document'] : [];
            $sourcePath = (string) ($row['path'] ?? '');
            $profile = (string) ($document['profile'] ?? ($row['name'] ?? ''));
            if ($profile === '') {
                continue;
            }

            $version = (int) ($document['version'] ?? 1);
            $disk = (string) ($document['disk'] ?? 'local');
            if (!in_array($disk, ['local', 's3'], true)) {
                $state->diagnostics->error(
                    code: 'FDY2209_UPLOAD_DISK_INVALID',
                    category: 'validation',
                    message: sprintf('Upload profile %s uses unsupported disk %s.', $profile, $disk),
                    nodeId: 'upload_profile:' . $profile,
                    sourcePath: $sourcePath,
                    suggestedFix: 'Use disk: local or disk: s3.',
                    pass: $this->name(),
                );
            }

            $featureMap = $this->uploadFeatureMap(
                profile: $profile,
                overrides: is_array($document['feature_names'] ?? null) ? $document['feature_names'] : [],
            );

            $nodeId = 'upload_profile:' . $profile;
            $state->graph->addNode(new UploadProfileNode(
                id: $nodeId,
                sourcePath: $sourcePath,
                payload: [
                    'profile' => $profile,
                    'version' => $version,
                    'disk' => $disk,
                    'visibility' => (string) ($document['visibility'] ?? 'private'),
                    'allowed_mime_types' => $this->sortedStrings((array) ($document['allowed_mime_types'] ?? [])),
                    'max_size_kb' => (int) ($document['max_size_kb'] ?? 5120),
                    'ownership' => is_array($document['ownership'] ?? null) ? $document['ownership'] : [],
                    'feature_map' => $featureMap,
                ],
                sourceRegion: ['line_start' => 1, 'line_end' => null],
                graphCompatibility: [1],
            ));

            foreach ($featureMap as $operation => $feature) {
                if (!$state->graph->hasNode('feature:' . $feature)) {
                    $state->diagnostics->warning(
                        code: 'FDY2210_UPLOAD_FEATURE_MISSING',
                        category: 'validation',
                        message: sprintf('Upload profile %s expects %s feature %s but it was not found.', $profile, $operation, $feature),
                        nodeId: $nodeId,
                        sourcePath: $sourcePath,
                        pass: $this->name(),
                    );
                    continue;
                }

                $state->graph->addEdge(GraphEdge::make('upload_profile_to_feature', $nodeId, 'feature:' . $feature, ['operation' => $operation]));
            }
        }

        foreach ($this->sortedRows((array) ($specs['starter'] ?? [])) as $row) {
            $document = is_array($row['document'] ?? null) ? $row['document'] : [];
            $sourcePath = (string) ($row['path'] ?? '');
            $starter = (string) ($document['starter'] ?? ($row['name'] ?? ''));
            if ($starter === '') {
                continue;
            }

            $features = $this->sortedStrings((array) ($document['features'] ?? []));
            $nodeId = 'starter_kit:' . $starter;
            $state->graph->addNode(new StarterKitNode(
                id: $nodeId,
                sourcePath: $sourcePath,
                payload: [
                    'starter' => $starter,
                    'version' => (int) ($document['version'] ?? 1),
                    'features' => $features,
                    'auth_mode' => (string) ($document['auth_mode'] ?? ''),
                    'pipeline_defaults' => is_array($document['pipeline_defaults'] ?? null) ? $document['pipeline_defaults'] : [],
                ],
                sourceRegion: ['line_start' => 1, 'line_end' => null],
                graphCompatibility: [1],
            ));

            foreach ($features as $feature) {
                if (!$state->graph->hasNode('feature:' . $feature)) {
                    $state->diagnostics->warning(
                        code: 'FDY2211_STARTER_FEATURE_MISSING',
                        category: 'validation',
                        message: sprintf('Starter %s references missing feature %s.', $starter, $feature),
                        nodeId: $nodeId,
                        sourcePath: $sourcePath,
                        pass: $this->name(),
                    );
                    continue;
                }

                $state->graph->addEdge(GraphEdge::make('starter_kit_to_feature', $nodeId, 'feature:' . $feature));
            }
        }
    }

    /**
     * @param array<string,array<string,mixed>> $rows
     * @return array<int,array<string,mixed>>
     */
    private function sortedRows(array $rows): array
    {
        ksort($rows);

        return array_values($rows);
    }

    /**
     * @param array<string,mixed> $fields
     * @return array<string,array<string,mixed>>
     */
    private function normalizeFields(array $fields, CompilationState $state, string $resource, string $sourcePath): array
    {
        $normalized = [];

        foreach ($fields as $name => $definition) {
            if (!is_string($name) || $name === '' || !is_array($definition)) {
                continue;
            }

            $type = (string) ($definition['type'] ?? 'string');
            if (!in_array($type, $this->allowedFieldTypes, true)) {
                $state->diagnostics->error(
                    code: 'FDY2212_RESOURCE_FIELD_TYPE_INVALID',
                    category: 'validation',
                    message: sprintf('Resource %s field %s has unsupported type %s.', $resource, $name, $type),
                    nodeId: 'resource:' . $resource,
                    sourcePath: $sourcePath,
                    pass: $this->name(),
                );
            }

            $normalized[$name] = [
                'type' => $type,
                'required' => (bool) ($definition['required'] ?? false),
                'form' => (string) ($definition['form'] ?? 'text'),
                'list' => (bool) ($definition['list'] ?? false),
                'search' => (bool) ($definition['search'] ?? false),
                'filter' => (bool) ($definition['filter'] ?? false),
                'sort' => (bool) ($definition['sort'] ?? false),
                'unique' => (bool) ($definition['unique'] ?? false),
                'maxLength' => isset($definition['maxLength']) ? (int) $definition['maxLength'] : null,
                'enum' => array_values(array_map('strval', (array) ($definition['enum'] ?? []))),
            ];
        }

        ksort($normalized);

        return $normalized;
    }

    /**
     * @param array<int,mixed> $operations
     * @return array<int,string>
     */
    private function normalizeOperations(array $operations): array
    {
        if ($operations === []) {
            return $this->allowedCrudOperations;
        }

        $result = [];
        foreach ($this->sortedStrings($operations) as $operation) {
            if (in_array($operation, $this->allowedCrudOperations, true)) {
                $result[] = $operation;
            }
        }

        if ($result === []) {
            return $this->allowedCrudOperations;
        }

        usort(
            $result,
            fn (string $a, string $b): int => array_search($a, $this->allowedCrudOperations, true)
                <=> array_search($b, $this->allowedCrudOperations, true),
        );

        return $result;
    }

    /**
     * @param array<string,mixed> $overrides
     * @return array<string,string>
     */
    private function crudFeatureMap(string $resource, array $operations, array $overrides): array
    {
        $singular = $this->singularize($resource);

        $defaults = [
            'list' => 'list_' . $resource,
            'view' => 'view_' . $singular,
            'create' => 'create_' . $singular,
            'update' => 'update_' . $singular,
            'delete' => 'delete_' . $singular,
        ];

        $map = [];
        foreach ($operations as $operation) {
            $override = (string) ($overrides[$operation] ?? '');
            $map[$operation] = $override !== '' ? $override : $defaults[$operation];
        }

        ksort($map);

        return $map;
    }

    /**
     * @param array<string,mixed> $overrides
     * @return array<string,string>
     */
    private function uploadFeatureMap(string $profile, array $overrides): array
    {
        $defaults = match ($profile) {
            'avatar' => [
                'upload' => 'upload_avatar',
                'attach' => 'attach_avatar',
            ],
            default => [
                'upload' => 'upload_attachment',
                'attach' => 'attach_attachment',
                'delete' => 'delete_attachment',
            ],
        };

        foreach ($defaults as $operation => $feature) {
            $override = (string) ($overrides[$operation] ?? '');
            if ($override !== '') {
                $defaults[$operation] = $override;
            }
        }

        ksort($defaults);

        return $defaults;
    }

    /**
     * @param array<string,array<string,mixed>> $fields
     * @return array<int,array<string,mixed>>
     */
    private function formFields(array $fields): array
    {
        $rows = [];
        foreach ($fields as $name => $field) {
            if (!is_array($field)) {
                continue;
            }

            $rows[] = [
                'name' => $name,
                'type' => (string) ($field['form'] ?? 'text'),
                'required' => (bool) ($field['required'] ?? false),
                'help' => (string) ($field['help'] ?? ''),
            ];
        }

        usort(
            $rows,
            static fn (array $a, array $b): int => strcmp((string) ($a['name'] ?? ''), (string) ($b['name'] ?? '')),
        );

        return $rows;
    }

    /**
     * @param array<int,mixed> $values
     * @return array<int,string>
     */
    private function sortedStrings(array $values): array
    {
        $values = array_values(array_map('strval', $values));
        $values = array_values(array_filter($values, static fn (string $value): bool => $value !== ''));
        $values = array_values(array_unique($values));
        sort($values);

        return $values;
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
