<?php
declare(strict_types=1);

namespace Foundry\Config;

use Foundry\Compiler\Extensions\ExtensionRegistry;
use Foundry\Schema\JsonSchemaValidator;
use Foundry\Schema\ValidationError;
use Foundry\Support\Paths;

final class ConfigValidator
{
    private readonly ConfigSchemaRegistry $schemas;
    private readonly ConfigCompatibilityNormalizer $normalizer;
    private readonly JsonSchemaValidator $validator;

    public function __construct(
        ?ConfigSchemaRegistry $schemas = null,
        ?ConfigCompatibilityNormalizer $normalizer = null,
        ?JsonSchemaValidator $validator = null,
    ) {
        $this->schemas = $schemas ?? (new ConfigSchemaCatalog())->registry();
        $this->normalizer = $normalizer ?? new ConfigCompatibilityNormalizer();
        $this->validator = $validator ?? new JsonSchemaValidator();
    }

    public function registry(): ConfigSchemaRegistry
    {
        return $this->schemas;
    }

    /**
     * @param array<string,array<string,mixed>> $discoveredFeatures
     * @param array<string,array<string,array<string,mixed>>> $discoveredDefinitions
     */
    public function validateProject(
        Paths $paths,
        array $discoveredFeatures = [],
        array $discoveredDefinitions = [],
        ?ExtensionRegistry $extensions = null,
    ): ConfigValidationReport {
        $items = [];
        $validatedSources = [];

        foreach ($this->schemas->all() as $schemaId => $schema) {
            $relativePath = (string) ($schema['x-foundry-path'] ?? '');
            if ($relativePath === '') {
                continue;
            }

            $absolutePath = $paths->join($relativePath);
            $optional = (bool) ($schema['x-foundry-optional'] ?? false);
            if (!is_file($absolutePath)) {
                if (!$optional) {
                    $items[] = new ConfigValidationIssue(
                        code: 'FDY1701_CONFIG_FILE_LOAD_FAILED',
                        severity: 'error',
                        category: 'config',
                        schemaId: $schemaId,
                        message: sprintf('Required config file %s is missing.', $relativePath),
                        sourcePath: $relativePath,
                        configPath: '$',
                        expected: 'existing file',
                        actual: 'missing',
                        suggestedFix: 'Create ' . $relativePath . ' so it matches the ' . $schemaId . ' schema.',
                    );
                }

                continue;
            }

            $validatedSources[] = $relativePath;
            $payload = $this->loadPhpArray($absolutePath, $relativePath, $schemaId);
            if ($payload instanceof ConfigValidationIssue) {
                $items[] = $payload;
                continue;
            }

            if (!$this->isSchemaCompatibleRoot($payload, $schema)) {
                $items[] = new ConfigValidationIssue(
                    code: 'FDY1702_CONFIG_RETURN_TYPE_INVALID',
                    severity: 'error',
                    category: 'config',
                    schemaId: $schemaId,
                    message: sprintf('Config file %s must return an array-shaped value.', $relativePath),
                    sourcePath: $relativePath,
                    configPath: '$',
                    expected: (string) ($schema['type'] ?? 'array'),
                    actual: gettype($payload),
                    suggestedFix: 'Return a PHP array from ' . $relativePath . '.',
                );
                continue;
            }

            /** @var array<string,mixed> $payload */
            $normalized = $payload;
            if ($this->isAssociativeSchema($schema) && !array_is_list($payload)) {
                $normalizedResult = $this->normalizer->normalize($schemaId, $payload, $relativePath);
                $normalized = $normalizedResult['normalized'];
                $items = array_merge($items, $normalizedResult['issues']);
            }

            $items = array_merge($items, $this->schemaIssues(
                schemaId: $schemaId,
                schema: $schema,
                payload: $normalized,
                sourcePath: $relativePath,
            ));

            $items = array_merge($items, $this->crossFieldIssues(
                schemaId: $schemaId,
                payload: $normalized,
                sourcePath: $relativePath,
            ));
        }

        foreach ($discoveredFeatures as $feature => $row) {
            $manifestPath = (string) ($row['manifest_path'] ?? ('app/features/' . $feature . '/feature.yaml'));
            $route = is_array($row['manifest']['route'] ?? null) ? $row['manifest']['route'] : null;
            if ($route === null) {
                continue;
            }

            $validatedSources[] = $manifestPath;
            $items = array_merge($items, $this->schemaIssues(
                schemaId: 'routing.route',
                schema: $this->schemas->get('routing.route') ?? [],
                payload: $route,
                sourcePath: $manifestPath,
                nodeId: 'feature:' . $feature,
            ));
        }

        foreach ((array) ($discoveredDefinitions['search_index'] ?? []) as $name => $row) {
            $document = is_array($row['document'] ?? null) ? $row['document'] : [];
            $sourcePath = (string) ($row['path'] ?? '');
            if ($document === [] || $sourcePath === '') {
                continue;
            }

            $validatedSources[] = $sourcePath;
            $items = array_merge($items, $this->schemaIssues(
                schemaId: 'definition.search_index',
                schema: $this->schemas->get('definition.search_index') ?? [],
                payload: $document,
                sourcePath: $sourcePath,
                nodeId: 'search_index:' . $name,
            ));
        }

        if ($extensions !== null) {
            $items = array_merge($items, $this->extensionIssues($extensions));
        }

        usort(
            $items,
            static fn (ConfigValidationIssue $a, ConfigValidationIssue $b): int =>
                strcmp($a->sourcePath ?? '', $b->sourcePath ?? '')
                ?: strcmp($a->configPath ?? '', $b->configPath ?? '')
                ?: strcmp($a->code, $b->code),
        );

        return new ConfigValidationReport($items, $this->schemas->all(), $validatedSources);
    }

    /**
     * @return array<int,ConfigValidationIssue>
     */
    private function extensionIssues(ExtensionRegistry $extensions): array
    {
        $items = [];
        foreach ($extensions->inspectRows() as $row) {
            $name = (string) ($row['name'] ?? '');
            $sourcePath = (string) ($row['source_path'] ?? '');
            $extension = $name !== '' ? $extensions->extension($name) : null;
            if ($extension === null) {
                continue;
            }

            $items = array_merge($items, $this->schemaIssues(
                schemaId: 'extension.descriptor',
                schema: $this->schemas->get('extension.descriptor') ?? [],
                payload: $extension->descriptor()->toArray(),
                sourcePath: $sourcePath !== '' ? $sourcePath : $name,
                nodeId: 'extension:' . $name,
            ));

            foreach ($extension->packs() as $pack) {
                $items = array_merge($items, $this->schemaIssues(
                    schemaId: 'extension.pack',
                    schema: $this->schemas->get('extension.pack') ?? [],
                    payload: $pack->toArray(),
                    sourcePath: $sourcePath !== '' ? $sourcePath : $name,
                    nodeId: 'pack:' . $pack->name,
                ));
            }

            foreach ($extension->pipelineStages() as $stage) {
                $items = array_merge($items, $this->schemaIssues(
                    schemaId: 'pipeline.stage_definition',
                    schema: $this->schemas->get('pipeline.stage_definition') ?? [],
                    payload: $stage->toArray(),
                    sourcePath: $sourcePath !== '' ? $sourcePath : $name,
                    nodeId: 'pipeline_stage:' . $stage->name,
                ));
            }

            foreach ($extension->pipelineInterceptors() as $interceptor) {
                $items = array_merge($items, $this->schemaIssues(
                    schemaId: 'pipeline.interceptor',
                    schema: $this->schemas->get('pipeline.interceptor') ?? [],
                    payload: [
                        'id' => $interceptor->id(),
                        'stage' => $interceptor->stage(),
                        'priority' => $interceptor->priority(),
                        'dangerous' => $interceptor->isDangerous(),
                    ],
                    sourcePath: $sourcePath !== '' ? $sourcePath : $name,
                    nodeId: 'interceptor:' . $interceptor->id(),
                ));
            }
        }

        return $items;
    }

    /**
     * @param array<string,mixed> $schema
     * @param array<mixed> $payload
     * @return array<int,ConfigValidationIssue>
     */
    private function schemaIssues(string $schemaId, array $schema, array $payload, string $sourcePath, ?string $nodeId = null): array
    {
        if ($schema === []) {
            return [];
        }

        $result = $this->validator->validateData($payload, $schema);
        $issues = [];
        foreach ($result->errors as $error) {
            $issues[] = $this->issueFromValidationError($schemaId, $sourcePath, $error, $nodeId);
        }

        return $issues;
    }

    /**
     * @param array<string,mixed> $payload
     * @return array<int,ConfigValidationIssue>
     */
    private function crossFieldIssues(string $schemaId, array $payload, string $sourcePath): array
    {
        return match ($schemaId) {
            'config.database' => $this->databaseCrossFieldIssues($payload, $sourcePath),
            'config.auth' => $this->authCrossFieldIssues($payload, $sourcePath),
            'config.cache' => $this->cacheCrossFieldIssues($payload, $sourcePath),
            'config.queue' => $this->queueCrossFieldIssues($payload, $sourcePath),
            'config.ai' => $this->aiCrossFieldIssues($payload, $sourcePath),
            default => [],
        };
    }

    /**
     * @param array<string,mixed> $payload
     * @return array<int,ConfigValidationIssue>
     */
    private function databaseCrossFieldIssues(array $payload, string $sourcePath): array
    {
        $default = (string) ($payload['default'] ?? '');
        $connections = is_array($payload['connections'] ?? null) ? $payload['connections'] : [];
        if ($default === '' || isset($connections[$default])) {
            return [];
        }

        return [
            new ConfigValidationIssue(
                code: 'FDY1705_CONFIG_CROSS_FIELD_INVALID',
                severity: 'error',
                category: 'config',
                schemaId: 'config.database',
                message: sprintf('Database default connection %s is not defined under $.connections.', $default),
                sourcePath: $sourcePath,
                configPath: '$.default',
                expected: 'defined connection key',
                actual: $default,
                suggestedFix: 'Add $.connections.' . $default . ' or change $.default to an existing connection.',
            ),
        ];
    }

    /**
     * @param array<string,mixed> $payload
     * @return array<int,ConfigValidationIssue>
     */
    private function authCrossFieldIssues(array $payload, string $sourcePath): array
    {
        $default = (string) ($payload['default'] ?? '');
        $strategies = is_array($payload['strategies'] ?? null) ? $payload['strategies'] : [];
        if ($default === '' || $strategies === [] || isset($strategies[$default])) {
            return [];
        }

        return [
            new ConfigValidationIssue(
                code: 'FDY1705_CONFIG_CROSS_FIELD_INVALID',
                severity: 'error',
                category: 'config',
                schemaId: 'config.auth',
                message: sprintf('Auth default strategy %s is not configured under $.strategies.', $default),
                sourcePath: $sourcePath,
                configPath: '$.default',
                expected: 'configured strategy key',
                actual: $default,
                suggestedFix: 'Add $.strategies.' . $default . ' or change $.default to a configured strategy.',
            ),
        ];
    }

    /**
     * @param array<string,mixed> $payload
     * @return array<int,ConfigValidationIssue>
     */
    private function cacheCrossFieldIssues(array $payload, string $sourcePath): array
    {
        $default = (string) ($payload['default'] ?? '');
        $stores = is_array($payload['stores'] ?? null) ? $payload['stores'] : [];
        if ($default === 'array' || ($default !== '' && isset($stores[$default]))) {
            return [];
        }

        if ($default === '') {
            return [];
        }

        return [
            new ConfigValidationIssue(
                code: 'FDY1705_CONFIG_CROSS_FIELD_INVALID',
                severity: 'error',
                category: 'config',
                schemaId: 'config.cache',
                message: sprintf('Cache default store %s is not configured under $.stores.', $default),
                sourcePath: $sourcePath,
                configPath: '$.default',
                expected: 'configured store key',
                actual: $default,
                suggestedFix: 'Add $.stores.' . $default . ' or change $.default to array or another configured store.',
            ),
        ];
    }

    /**
     * @param array<string,mixed> $payload
     * @return array<int,ConfigValidationIssue>
     */
    private function queueCrossFieldIssues(array $payload, string $sourcePath): array
    {
        $default = (string) ($payload['default'] ?? '');
        $drivers = is_array($payload['drivers'] ?? null) ? $payload['drivers'] : [];
        if ($default === 'sync' || ($default !== '' && isset($drivers[$default]))) {
            return [];
        }

        if ($default === '') {
            return [];
        }

        return [
            new ConfigValidationIssue(
                code: 'FDY1705_CONFIG_CROSS_FIELD_INVALID',
                severity: 'error',
                category: 'config',
                schemaId: 'config.queue',
                message: sprintf('Queue default driver %s is not configured under $.drivers.', $default),
                sourcePath: $sourcePath,
                configPath: '$.default',
                expected: 'configured driver key',
                actual: $default,
                suggestedFix: 'Add $.drivers.' . $default . ' or change $.default to sync or another configured driver.',
            ),
        ];
    }

    /**
     * @param array<string,mixed> $payload
     * @return array<int,ConfigValidationIssue>
     */
    private function aiCrossFieldIssues(array $payload, string $sourcePath): array
    {
        $default = (string) ($payload['default'] ?? '');
        $providers = is_array($payload['providers'] ?? null) ? $payload['providers'] : [];
        if ($default === 'static' || ($default !== '' && isset($providers[$default]))) {
            return [];
        }

        if ($default === '') {
            return [];
        }

        return [
            new ConfigValidationIssue(
                code: 'FDY1705_CONFIG_CROSS_FIELD_INVALID',
                severity: 'error',
                category: 'config',
                schemaId: 'config.ai',
                message: sprintf('AI default provider %s is not configured under $.providers.', $default),
                sourcePath: $sourcePath,
                configPath: '$.default',
                expected: 'configured provider key',
                actual: $default,
                suggestedFix: 'Add $.providers.' . $default . ' or change $.default to static or another configured provider.',
            ),
        ];
    }

    private function issueFromValidationError(string $schemaId, string $sourcePath, ValidationError $error, ?string $nodeId = null): ConfigValidationIssue
    {
        $message = sprintf(
            'Config schema %s failed at %s. %s',
            $schemaId,
            $error->path,
            $error->message,
        );

        return new ConfigValidationIssue(
            code: 'FDY1703_CONFIG_SCHEMA_VIOLATION',
            severity: 'error',
            category: 'config',
            schemaId: $schemaId,
            message: $message,
            sourcePath: $sourcePath,
            configPath: $error->path,
            expected: $error->expected,
            actual: $error->actual,
            suggestedFix: $error->suggestedFix ?? ('Update ' . $sourcePath . ' so ' . $error->path . ' matches the expected schema.'),
            nodeId: $nodeId,
        );
    }

    private function loadPhpArray(string $absolutePath, string $relativePath, string $schemaId): mixed
    {
        try {
            return (static function (string $path): mixed {
                return require $path;
            })($absolutePath);
        } catch (\Throwable $error) {
            return new ConfigValidationIssue(
                code: 'FDY1701_CONFIG_FILE_LOAD_FAILED',
                severity: 'error',
                category: 'config',
                schemaId: $schemaId,
                message: sprintf('Config file %s could not be loaded: %s', $relativePath, $error->getMessage()),
                sourcePath: $relativePath,
                configPath: '$',
                expected: 'loadable PHP config file',
                actual: $error::class,
                suggestedFix: 'Fix the PHP syntax or runtime error in ' . $relativePath . '.',
                details: ['exception' => $error::class],
            );
        }
    }

    /**
     * @param array<string,mixed> $schema
     */
    private function isAssociativeSchema(array $schema): bool
    {
        return ($schema['type'] ?? null) === 'object';
    }

    /**
     * @param array<string,mixed> $schema
     */
    private function isSchemaCompatibleRoot(mixed $payload, array $schema): bool
    {
        $type = $schema['type'] ?? null;
        if ($type === 'array') {
            return is_array($payload) && array_is_list($payload);
        }

        if ($type === 'object') {
            return is_array($payload);
        }

        return is_array($payload);
    }
}
