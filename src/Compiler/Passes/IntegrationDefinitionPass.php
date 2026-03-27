<?php

declare(strict_types=1);

namespace Foundry\Compiler\Passes;

use Foundry\Compiler\CompilationState;
use Foundry\Compiler\CompilerPass;
use Foundry\Compiler\GraphEdge;
use Foundry\Compiler\IR\ApiResourceNode;
use Foundry\Compiler\IR\NotificationNode;
use Foundry\Compiler\IR\SchemaNode;
use Foundry\Support\Json;

final class IntegrationDefinitionPass implements CompilerPass
{
    public function name(): string
    {
        return 'integration_definitions';
    }

    public function run(CompilationState $state): void
    {
        $definitions = $state->discoveredDefinitions;
        if ($definitions === []) {
            return;
        }

        $this->processNotifications($state, (array) ($definitions['notification'] ?? []));
        $this->processApiResources($state, (array) ($definitions['api_resource'] ?? []));
    }

    /**
     * @param array<string,array<string,mixed>> $rows
     */
    private function processNotifications(CompilationState $state, array $rows): void
    {
        $seen = [];

        foreach ($this->sortedRows($rows) as $row) {
            $document = is_array($row['document'] ?? null) ? $row['document'] : [];
            $sourcePath = (string) ($row['path'] ?? '');
            $name = (string) ($document['notification'] ?? ($row['name'] ?? ''));
            if ($name === '') {
                continue;
            }

            if (isset($seen[$name])) {
                $state->diagnostics->error(
                    code: 'FDY2308_NOTIFICATION_DUPLICATE',
                    category: 'notifications',
                    message: sprintf('Duplicate notification definition detected for %s.', $name),
                    nodeId: 'notification:' . $name,
                    sourcePath: $sourcePath,
                    relatedNodes: ['notification:' . $name],
                    pass: $this->name(),
                );
                continue;
            }
            $seen[$name] = true;

            $version = (int) ($document['version'] ?? 1);
            if ($version !== 1) {
                $state->diagnostics->error(
                    code: 'FDY2301_NOTIFICATION_DEFINITION_VERSION_UNSUPPORTED',
                    category: 'migrations',
                    message: sprintf('Unsupported notification definition version %d for %s.', $version, $name),
                    nodeId: 'notification:' . $name,
                    sourcePath: $sourcePath,
                    suggestedFix: 'Set version: 1 or run migrations.',
                    pass: $this->name(),
                );
            }

            $channel = strtolower((string) ($document['channel'] ?? 'mail'));
            if ($channel !== 'mail') {
                $state->diagnostics->error(
                    code: 'FDY2302_NOTIFICATION_CHANNEL_UNSUPPORTED',
                    category: 'notifications',
                    message: sprintf('Unsupported notification channel %s for %s.', $channel, $name),
                    nodeId: 'notification:' . $name,
                    sourcePath: $sourcePath,
                    suggestedFix: 'Use channel: mail.',
                    pass: $this->name(),
                );
            }

            $queue = (string) ($document['queue'] ?? 'default');
            if ($queue === '') {
                $state->diagnostics->error(
                    code: 'FDY2303_NOTIFICATION_QUEUE_MISSING',
                    category: 'notifications',
                    message: sprintf('Notification %s must define a queue.', $name),
                    nodeId: 'notification:' . $name,
                    sourcePath: $sourcePath,
                    suggestedFix: 'Set queue: default.',
                    pass: $this->name(),
                );
                $queue = 'default';
            }

            $template = (string) ($document['template'] ?? $name);
            $templatePath = $this->templatePath($template);
            if (!is_file($state->paths->join($templatePath))) {
                $state->diagnostics->error(
                    code: 'FDY2305_NOTIFICATION_TEMPLATE_MISSING',
                    category: 'notifications',
                    message: sprintf('Notification %s references missing template %s.', $name, $templatePath),
                    nodeId: 'notification:' . $name,
                    sourcePath: $sourcePath,
                    suggestedFix: 'Generate the notification template or fix template path.',
                    pass: $this->name(),
                );
            }

            $inputSchemaField = $document['input_schema'] ?? ('app/notifications/schemas/' . $name . '.input.schema.json');
            $inputSchemaPath = $this->schemaPath(
                is_string($inputSchemaField) ? $inputSchemaField : ('app/notifications/schemas/' . $name . '.input.schema.json'),
            );
            $schemaDocument = is_array($inputSchemaField)
                ? (array) $inputSchemaField
                : $this->loadSchemaDocument($state, $inputSchemaPath, 'notification:' . $name, $sourcePath);

            $nodeId = 'notification:' . $name;
            $dispatchFeatures = $this->sortedStrings((array) ($document['dispatch_features'] ?? []));

            $state->graph->addNode(new NotificationNode(
                id: $nodeId,
                sourcePath: $sourcePath,
                payload: [
                    'notification' => $name,
                    'version' => $version,
                    'channel' => $channel,
                    'queue' => $queue,
                    'template' => $template,
                    'template_path' => $templatePath,
                    'input_schema_path' => $inputSchemaPath,
                    'input_schema' => $schemaDocument,
                    'dispatch_features' => $dispatchFeatures,
                ],
                sourceRegion: ['line_start' => 1, 'line_end' => null],
                graphCompatibility: [1],
            ));

            $schemaNodeId = 'schema:' . $inputSchemaPath;
            if (!$state->graph->hasNode($schemaNodeId)) {
                $state->graph->addNode(new SchemaNode(
                    id: $schemaNodeId,
                    sourcePath: $inputSchemaPath,
                    payload: [
                        'path' => $inputSchemaPath,
                        'role' => 'notification_input',
                        'notification' => $name,
                        'document' => $schemaDocument,
                    ],
                    sourceRegion: ['line_start' => 1, 'line_end' => null],
                    graphCompatibility: [1],
                ));
            }
            $state->graph->addEdge(GraphEdge::make('notification_to_input_schema', $nodeId, $schemaNodeId));

            foreach ($dispatchFeatures as $feature) {
                $featureId = 'feature:' . $feature;
                if (!$state->graph->hasNode($featureId)) {
                    $state->diagnostics->warning(
                        code: 'FDY2304_NOTIFICATION_FEATURE_MISSING',
                        category: 'linking',
                        message: sprintf('Notification %s references missing dispatch feature %s.', $name, $feature),
                        nodeId: $nodeId,
                        sourcePath: $sourcePath,
                        suggestedFix: 'Generate feature or remove from dispatch_features.',
                        pass: $this->name(),
                    );
                    continue;
                }

                $state->graph->addEdge(GraphEdge::make('feature_to_notification_dispatch', $featureId, $nodeId));
                $state->graph->addEdge(GraphEdge::make('notification_to_feature', $nodeId, $featureId));
            }
        }
    }

    /**
     * @param array<string,array<string,mixed>> $rows
     */
    private function processApiResources(CompilationState $state, array $rows): void
    {
        $seen = [];

        foreach ($this->sortedRows($rows) as $row) {
            $document = is_array($row['document'] ?? null) ? $row['document'] : [];
            $sourcePath = (string) ($row['path'] ?? '');
            $resource = (string) ($document['resource'] ?? ($row['name'] ?? ''));
            if ($resource === '') {
                continue;
            }

            if (isset($seen[$resource])) {
                $state->diagnostics->error(
                    code: 'FDY2313_API_RESOURCE_DUPLICATE',
                    category: 'api',
                    message: sprintf('Duplicate api resource definition detected for %s.', $resource),
                    nodeId: 'api_resource:' . $resource,
                    sourcePath: $sourcePath,
                    pass: $this->name(),
                );
                continue;
            }
            $seen[$resource] = true;

            $version = (int) ($document['version'] ?? 1);
            if ($version !== 1) {
                $state->diagnostics->error(
                    code: 'FDY2310_API_RESOURCE_DEFINITION_VERSION_UNSUPPORTED',
                    category: 'migrations',
                    message: sprintf('Unsupported api resource definition version %d for %s.', $version, $resource),
                    nodeId: 'api_resource:' . $resource,
                    sourcePath: $sourcePath,
                    suggestedFix: 'Set version: 1 or run migrations.',
                    pass: $this->name(),
                );
            }

            $style = (string) ($document['style'] ?? 'api');
            if ($style !== 'api') {
                $state->diagnostics->warning(
                    code: 'FDY2314_API_RESOURCE_STYLE_MISMATCH',
                    category: 'api',
                    message: sprintf('Api resource %s should use style: api.', $resource),
                    nodeId: 'api_resource:' . $resource,
                    sourcePath: $sourcePath,
                    pass: $this->name(),
                );
            }

            $operations = $this->normalizeOperations((array) ($document['features'] ?? []));
            $featureMap = $this->apiFeatureMap(
                resource: $resource,
                operations: $operations,
                overrides: is_array($document['feature_names'] ?? null) ? $document['feature_names'] : [],
            );

            $nodeId = 'api_resource:' . $resource;
            $state->graph->addNode(new ApiResourceNode(
                id: $nodeId,
                sourcePath: $sourcePath,
                payload: [
                    'resource' => $resource,
                    'version' => $version,
                    'style' => 'api',
                    'model' => is_array($document['model'] ?? null) ? $document['model'] : [],
                    'fields' => is_array($document['fields'] ?? null) ? $document['fields'] : [],
                    'auth' => is_array($document['auth'] ?? null) ? $document['auth'] : [],
                    'operations' => $operations,
                    'feature_map' => $featureMap,
                    'response_convention' => [
                        'envelope' => 'data',
                        'errors' => 'error',
                    ],
                ],
                sourceRegion: ['line_start' => 1, 'line_end' => null],
                graphCompatibility: [1],
            ));

            $resourceId = 'resource:' . $resource;
            if ($state->graph->hasNode($resourceId)) {
                $state->graph->addEdge(GraphEdge::make('api_resource_to_resource', $nodeId, $resourceId));
                $state->graph->addEdge(GraphEdge::make('resource_to_api_resource', $resourceId, $nodeId));
            }

            foreach ($featureMap as $operation => $feature) {
                $featureId = 'feature:' . $feature;
                if (!$state->graph->hasNode($featureId)) {
                    $state->diagnostics->warning(
                        code: 'FDY2311_API_RESOURCE_FEATURE_MISSING',
                        category: 'linking',
                        message: sprintf('Api resource %s expects %s feature %s but it was not found.', $resource, $operation, $feature),
                        nodeId: $nodeId,
                        sourcePath: $sourcePath,
                        suggestedFix: 'Generate API features or update feature_names.',
                        pass: $this->name(),
                    );
                    continue;
                }

                $state->graph->addEdge(GraphEdge::make('api_resource_to_feature', $nodeId, $featureId, ['operation' => $operation]));
                $state->graph->addEdge(GraphEdge::make('feature_to_api_resource', $featureId, $nodeId, ['operation' => $operation]));

                $featureNode = $state->graph->node($featureId);
                $route = is_array($featureNode?->payload()['route'] ?? null) ? $featureNode?->payload()['route'] : [];
                $path = (string) ($route['path'] ?? '');
                if ($path !== '' && !str_starts_with($path, '/api')) {
                    $state->diagnostics->error(
                        code: 'FDY2312_API_FEATURE_ROUTE_NOT_API',
                        category: 'routing',
                        message: sprintf('API feature %s route must start with /api (got %s).', $feature, $path),
                        nodeId: $featureId,
                        sourcePath: $featureNode?->sourcePath() ?? $sourcePath,
                        pass: $this->name(),
                    );
                }
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
     * @param array<int,mixed> $operations
     * @return array<int,string>
     */
    private function normalizeOperations(array $operations): array
    {
        $allowed = ['list', 'view', 'create', 'update', 'delete'];
        if ($operations === []) {
            return $allowed;
        }

        $normalized = [];
        foreach ($this->sortedStrings($operations) as $operation) {
            if (in_array($operation, $allowed, true)) {
                $normalized[] = $operation;
            }
        }

        if ($normalized === []) {
            return $allowed;
        }

        usort(
            $normalized,
            static fn(string $a, string $b): int => array_search($a, $allowed, true) <=> array_search($b, $allowed, true),
        );

        return $normalized;
    }

    /**
     * @param array<string,mixed> $overrides
     * @param array<int,string> $operations
     * @return array<string,string>
     */
    private function apiFeatureMap(string $resource, array $operations, array $overrides): array
    {
        $singular = $this->singularize($resource);
        $defaults = [
            'list' => 'api_list_' . $resource,
            'view' => 'api_view_' . $singular,
            'create' => 'api_create_' . $singular,
            'update' => 'api_update_' . $singular,
            'delete' => 'api_delete_' . $singular,
        ];

        $map = [];
        foreach ($operations as $operation) {
            $override = (string) ($overrides[$operation] ?? '');
            $map[$operation] = $override !== '' ? $override : $defaults[$operation];
        }

        ksort($map);

        return $map;
    }

    private function templatePath(string $template): string
    {
        if (str_starts_with($template, 'app/')) {
            return $template;
        }

        return 'app/notifications/templates/' . trim($template, '/') . '.mail.php';
    }

    private function schemaPath(string $path): string
    {
        if ($path === '') {
            return 'app/notifications/schemas/notification.input.schema.json';
        }

        if (str_starts_with($path, 'app/')) {
            return $path;
        }

        return 'app/notifications/schemas/' . ltrim($path, '/');
    }

    /**
     * @return array<string,mixed>|null
     */
    private function loadSchemaDocument(CompilationState $state, string $relativePath, string $nodeId, string $sourcePath): ?array
    {
        $absolutePath = $state->paths->join($relativePath);
        if (!is_file($absolutePath)) {
            $state->diagnostics->error(
                code: 'FDY2306_NOTIFICATION_SCHEMA_MISSING',
                category: 'schemas',
                message: sprintf('Notification input schema file not found: %s.', $relativePath),
                nodeId: $nodeId,
                sourcePath: $sourcePath,
                pass: $this->name(),
            );

            return null;
        }

        $raw = file_get_contents($absolutePath);
        if (!is_string($raw) || $raw === '') {
            $state->diagnostics->error(
                code: 'FDY2307_NOTIFICATION_SCHEMA_INVALID',
                category: 'schemas',
                message: sprintf('Notification input schema file could not be read: %s.', $relativePath),
                nodeId: $nodeId,
                sourcePath: $sourcePath,
                pass: $this->name(),
            );

            return null;
        }

        try {
            return Json::decodeAssoc($raw);
        } catch (\Throwable $error) {
            $state->diagnostics->error(
                code: 'FDY2307_NOTIFICATION_SCHEMA_INVALID',
                category: 'schemas',
                message: $error->getMessage(),
                nodeId: $nodeId,
                sourcePath: $sourcePath,
                pass: $this->name(),
            );

            return null;
        }
    }

    /**
     * @param array<int,mixed> $values
     * @return array<int,string>
     */
    private function sortedStrings(array $values): array
    {
        $values = array_values(array_map('strval', $values));
        $values = array_values(array_filter($values, static fn(string $value): bool => $value !== ''));
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
