<?php

declare(strict_types=1);

namespace Foundry\Export;

use Foundry\Compiler\ApplicationGraph;
use Foundry\Support\Json;
use Foundry\Support\Yaml;

final class OpenApiExporter
{
    /**
     * @return array<string,mixed>
     */
    public function build(ApplicationGraph $graph): array
    {
        $paths = [];
        $schemas = [
            'ErrorEnvelope' => [
                'type' => 'object',
                'additionalProperties' => false,
                'required' => ['error'],
                'properties' => [
                    'error' => [
                        'type' => 'object',
                        'required' => ['code', 'message'],
                        'properties' => [
                            'code' => ['type' => 'string'],
                            'message' => ['type' => 'string'],
                            'details' => ['type' => 'object', 'additionalProperties' => true],
                        ],
                    ],
                ],
            ],
        ];

        foreach ($graph->nodesByType('feature') as $node) {
            $payload = $node->payload();
            $feature = (string) ($payload['feature'] ?? '');
            $route = is_array($payload['route'] ?? null) ? $payload['route'] : [];
            $path = (string) ($route['path'] ?? '');
            if ($feature === '' || $path === '' || !str_starts_with($path, '/api')) {
                continue;
            }

            $method = strtolower((string) ($route['method'] ?? 'get'));
            if ($method === '') {
                $method = 'get';
            }

            $auth = is_array($payload['auth'] ?? null) ? $payload['auth'] : [];
            $requiredAuth = (bool) ($auth['required'] ?? false);
            $permissions = array_values(array_map('strval', (array) ($auth['permissions'] ?? [])));
            $description = (string) ($payload['description'] ?? '');

            $inputSchemaPath = (string) ($payload['input_schema_path'] ?? '');
            $outputSchemaPath = (string) ($payload['output_schema_path'] ?? '');
            $inputSchema = is_array($payload['input_schema'] ?? null) ? $payload['input_schema'] : null;
            $outputSchema = is_array($payload['output_schema'] ?? null) ? $payload['output_schema'] : null;

            $inputComponent = $this->schemaComponentName($inputSchemaPath, $feature . '_input');
            $outputComponent = $this->schemaComponentName($outputSchemaPath, $feature . '_output');

            if ($inputSchema !== null) {
                $schemas[$inputComponent] = $inputSchema;
            }
            if ($outputSchema !== null) {
                $schemas[$outputComponent] = $outputSchema;
            }

            $operation = [
                'operationId' => $feature,
                'tags' => [$this->tagForFeature($feature, $path, $payload)],
                'summary' => $description !== '' ? $description : $feature,
                'responses' => [
                    '200' => [
                        'description' => 'Successful response.',
                        'content' => [
                            'application/json' => [
                                'schema' => ['$ref' => '#/components/schemas/' . $outputComponent],
                            ],
                        ],
                    ],
                    '400' => $this->errorResponse('Bad request.'),
                    '404' => $this->errorResponse('Resource not found.'),
                ],
            ];

            if ($requiredAuth) {
                $operation['security'] = [['bearerAuth' => []]];
                $operation['responses']['401'] = $this->errorResponse('Authentication required.');
            }

            if ($permissions !== []) {
                $operation['x-foundry-permissions'] = $permissions;
            }

            if (in_array($method, ['post', 'put', 'patch', 'delete'], true)) {
                $operation['requestBody'] = [
                    'required' => true,
                    'content' => [
                        'application/json' => [
                            'schema' => ['$ref' => '#/components/schemas/' . $inputComponent],
                        ],
                    ],
                ];
            }

            $paths[$path] ??= [];
            $paths[$path][$method] = $operation;
        }

        $document = [
            'openapi' => '3.1.0',
            'info' => [
                'title' => 'Foundry API',
                'version' => $graph->frameworkVersion(),
            ],
            'servers' => [
                ['url' => '/'],
            ],
            'paths' => $paths,
            'components' => [
                'securitySchemes' => [
                    'bearerAuth' => [
                        'type' => 'http',
                        'scheme' => 'bearer',
                    ],
                ],
                'schemas' => $schemas,
            ],
            'x-foundry' => [
                'graph_version' => $graph->graphVersion(),
                'source_hash' => $graph->sourceHash(),
                'compiled_at' => $graph->compiledAt(),
            ],
        ];

        return $this->sortMap($document);
    }

    public function render(array $document, string $format = 'json'): string
    {
        $format = strtolower($format);

        return match ($format) {
            'yaml', 'yml' => Yaml::dump($document),
            default => Json::encode($document, true),
        };
    }

    /**
     * @param array<string,mixed> $payload
     */
    private function tagForFeature(string $feature, string $path, array $payload): string
    {
        $resource = is_array($payload['resource'] ?? null) ? $payload['resource'] : [];
        $name = (string) ($resource['name'] ?? '');
        if ($name !== '') {
            return $name;
        }

        $parts = array_values(array_filter(explode('/', trim($path, '/')), static fn(string $part): bool => $part !== '' && $part !== 'api'));
        if ($parts !== []) {
            return (string) $parts[0];
        }

        if (str_starts_with($feature, 'api_')) {
            $suffix = substr($feature, 4);
            $parts = explode('_', $suffix);

            return (string) ($parts[1] ?? $parts[0] ?? 'api');
        }

        return 'api';
    }

    /**
     * @return array<string,mixed>
     */
    private function errorResponse(string $description): array
    {
        return [
            'description' => $description,
            'content' => [
                'application/json' => [
                    'schema' => ['$ref' => '#/components/schemas/ErrorEnvelope'],
                ],
            ],
        ];
    }

    private function schemaComponentName(string $path, string $fallback): string
    {
        if ($path === '') {
            return $this->normalizeComponentName($fallback);
        }

        $base = basename($path);
        $base = preg_replace('/\.schema\.json$/', '', $base) ?? $base;
        $base = preg_replace('/[^a-zA-Z0-9_]+/', '_', $base) ?? $base;
        $base = trim($base, '_');
        if ($base === '') {
            $base = $fallback;
        }

        $suffix = substr(hash('sha1', $path), 0, 8);

        return $this->normalizeComponentName($base . '_' . $suffix);
    }

    private function normalizeComponentName(string $name): string
    {
        $name = preg_replace('/[^a-zA-Z0-9_]+/', '_', $name) ?? $name;
        $name = trim($name, '_');
        if ($name === '') {
            return 'Schema';
        }

        $parts = explode('_', $name);
        $parts = array_values(array_filter($parts, static fn(string $part): bool => $part !== ''));

        return implode('', array_map(static fn(string $part): string => ucfirst(strtolower($part)), $parts));
    }

    /**
     * @return mixed
     */
    private function sortMap(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }

        if (array_is_list($value)) {
            $rows = [];
            foreach ($value as $item) {
                $rows[] = $this->sortMap($item);
            }

            return $rows;
        }

        $normalized = [];
        $keys = array_keys($value);
        $keys = array_values(array_map('strval', $keys));
        sort($keys);

        foreach ($keys as $key) {
            $normalized[$key] = $this->sortMap($value[$key]);
        }

        return $normalized;
    }
}
