<?php

declare(strict_types=1);

namespace Foundry\Pro\Generation;

use Foundry\Support\Str;

final class PromptFeaturePlanner
{
    /**
     * @param array<string,mixed> $bundle
     * @param array<string,mixed> $providerPlan
     * @return array<string,mixed>
     */
    public function plan(string $instruction, array $bundle, array $providerPlan = [], bool $deterministic = false): array
    {
        $baseline = $this->baselinePlan($instruction, $bundle);
        $merged = array_replace_recursive($baseline, $providerPlan);

        $feature = $this->normalizeFeature(
            is_array($merged['feature'] ?? null) ? $merged['feature'] : [],
            is_array($baseline['feature'] ?? null) ? $baseline['feature'] : [],
        );

        $workflow = null;
        if (is_array($merged['workflow'] ?? null)) {
            $workflow = $this->normalizeWorkflow($merged['workflow']);
        } elseif (is_array($baseline['workflow'] ?? null)) {
            $workflow = $this->normalizeWorkflow($baseline['workflow']);
        }

        return [
            'feature' => $feature,
            'workflow' => $workflow,
            'explanation' => trim((string) ($merged['explanation'] ?? ($baseline['explanation'] ?? ''))),
            'trace' => [
                'deterministic' => $deterministic,
                'selected_features' => array_values(array_map('strval', (array) ($bundle['selected_features'] ?? []))),
                'tokens' => array_values(array_map('strval', (array) ($bundle['tokens'] ?? []))),
                'derived_action' => (string) (($baseline['trace']['derived_action'] ?? 'create')),
                'derived_resource' => (string) (($baseline['trace']['derived_resource'] ?? 'items')),
                'provider_fields' => array_keys($providerPlan),
            ],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public function responseSchema(): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'properties' => [
                'explanation' => ['type' => 'string'],
                'feature' => [
                    'type' => 'object',
                    'additionalProperties' => false,
                    'properties' => [
                        'feature' => ['type' => 'string'],
                        'description' => ['type' => 'string'],
                        'kind' => ['type' => 'string'],
                        'route' => [
                            'type' => 'object',
                            'additionalProperties' => false,
                            'properties' => [
                                'method' => ['type' => 'string'],
                                'path' => ['type' => 'string'],
                            ],
                        ],
                        'input' => ['type' => 'object'],
                        'output' => ['type' => 'object'],
                        'auth' => ['type' => 'object'],
                        'database' => ['type' => 'object'],
                        'cache' => ['type' => 'object'],
                        'events' => ['type' => 'object'],
                        'jobs' => ['type' => 'object'],
                        'tests' => ['type' => 'object'],
                    ],
                ],
                'workflow' => [
                    'type' => 'object',
                    'additionalProperties' => false,
                    'properties' => [
                        'name' => ['type' => 'string'],
                        'definition' => ['type' => 'object'],
                    ],
                ],
            ],
        ];
    }

    /**
     * @param array<string,mixed> $bundle
     * @return array<string,mixed>
     */
    private function baselinePlan(string $instruction, array $bundle): array
    {
        $selectedFeatures = array_values(array_map('strval', (array) ($bundle['selected_features'] ?? [])));
        $relatedResource = $this->resourceFromBundle($bundle) ?? $this->resourceFromInstruction($instruction) ?? 'items';
        $action = $this->actionFromInstruction($instruction);
        $resourceSingular = $this->singularize($relatedResource);
        $resourcePlural = $this->pluralize($relatedResource);
        $featureName = $this->featureName($action, $resourceSingular, $resourcePlural);
        $route = $this->routeForAction($action, $resourcePlural);
        $mutating = !in_array($action, ['list', 'view'], true);
        $pastTense = $this->pastTense($action);

        $feature = [
            'feature' => $featureName,
            'description' => ucfirst(str_replace('_', ' ', $featureName)) . '.',
            'kind' => 'http',
            'owners' => ['product'],
            'route' => $route,
            'input' => [
                'fields' => $this->inputFields($action, $route),
            ],
            'output' => [
                'fields' => $this->outputFields($mutating, $route),
            ],
            'auth' => [
                'required' => $mutating,
                'strategies' => ['bearer'],
                'permissions' => $mutating ? [$resourcePlural . '.' . $action] : [],
            ],
            'database' => [
                'reads' => $action === 'list' || $action === 'view' ? [$resourcePlural] : [$resourcePlural],
                'writes' => $mutating ? [$resourcePlural] : [],
                'queries' => [$action . '_' . ($action === 'list' ? $resourcePlural : $resourceSingular)],
                'transactions' => $mutating ? 'required' : 'none',
            ],
            'cache' => [
                'reads' => [],
                'writes' => [],
                'invalidate' => $mutating ? [$resourcePlural . ':list'] : [],
            ],
            'events' => [
                'emit' => $mutating ? [$resourceSingular . '.' . $pastTense] : [],
                'subscribe' => [],
            ],
            'jobs' => [
                'dispatch' => [],
            ],
            'tests' => [
                'required' => $mutating ? ['contract', 'feature', 'auth'] : ['contract', 'feature'],
            ],
        ];

        $workflow = null;
        if ($this->shouldGenerateWorkflow($instruction)) {
            $workflowName = $resourcePlural . '_approval';
            $workflow = [
                'name' => $workflowName,
                'definition' => [
                    'resource' => $resourcePlural,
                    'states' => ['draft', 'pending_review', 'approved'],
                    'transitions' => [
                        'submit' => [
                            'from' => ['draft'],
                            'to' => 'pending_review',
                            'permission' => $resourcePlural . '.submit',
                            'emit' => [$resourceSingular . '.submitted'],
                        ],
                        'approve' => [
                            'from' => ['pending_review'],
                            'to' => 'approved',
                            'permission' => $resourcePlural . '.approve',
                            'emit' => [$resourceSingular . '.approved'],
                        ],
                    ],
                ],
            ];
        }

        return [
            'feature' => $feature,
            'workflow' => $workflow,
            'explanation' => sprintf(
                'Generated %s for %s using graph context from %s.',
                $featureName,
                $resourcePlural,
                $selectedFeatures === [] ? 'no matching features' : implode(', ', $selectedFeatures),
            ),
            'trace' => [
                'derived_action' => $action,
                'derived_resource' => $resourcePlural,
            ],
        ];
    }

    /**
     * @param array<string,mixed> $feature
     * @param array<string,mixed> $fallback
     * @return array<string,mixed>
     */
    private function normalizeFeature(array $feature, array $fallback): array
    {
        $featureName = Str::toSnakeCase((string) ($feature['feature'] ?? $fallback['feature'] ?? 'generated_feature'));
        if ($featureName === '') {
            $featureName = 'generated_feature';
        }

        $description = trim((string) ($feature['description'] ?? $fallback['description'] ?? 'Generated feature.'));
        if ($description === '') {
            $description = 'Generated feature.';
        }

        $route = is_array($feature['route'] ?? null) ? $feature['route'] : (is_array($fallback['route'] ?? null) ? $fallback['route'] : []);
        $routeMethod = strtoupper(trim((string) ($route['method'] ?? 'POST')));
        if (!in_array($routeMethod, ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS', 'HEAD'], true)) {
            $routeMethod = 'POST';
        }
        $routePath = trim((string) ($route['path'] ?? '/generated'));
        if ($routePath === '' || !str_starts_with($routePath, '/')) {
            $routePath = '/generated';
        }

        $auth = is_array($feature['auth'] ?? null) ? $feature['auth'] : (is_array($fallback['auth'] ?? null) ? $fallback['auth'] : []);
        $database = is_array($feature['database'] ?? null) ? $feature['database'] : (is_array($fallback['database'] ?? null) ? $fallback['database'] : []);
        $cache = is_array($feature['cache'] ?? null) ? $feature['cache'] : (is_array($fallback['cache'] ?? null) ? $fallback['cache'] : []);
        $events = is_array($feature['events'] ?? null) ? $feature['events'] : (is_array($fallback['events'] ?? null) ? $fallback['events'] : []);
        $jobs = is_array($feature['jobs'] ?? null) ? $feature['jobs'] : (is_array($fallback['jobs'] ?? null) ? $fallback['jobs'] : []);
        $tests = is_array($feature['tests'] ?? null) ? $feature['tests'] : (is_array($fallback['tests'] ?? null) ? $fallback['tests'] : []);
        $owners = array_values(array_map('strval', (array) ($feature['owners'] ?? $fallback['owners'] ?? ['product'])));
        sort($owners);

        return [
            'feature' => $featureName,
            'description' => $description,
            'kind' => (string) ($feature['kind'] ?? $fallback['kind'] ?? 'http'),
            'owners' => array_values(array_unique($owners)),
            'route' => [
                'method' => $routeMethod,
                'path' => $routePath,
            ],
            'input' => [
                'fields' => $this->normalizeFields((array) ($feature['input']['fields'] ?? $fallback['input']['fields'] ?? [])),
            ],
            'output' => [
                'fields' => $this->normalizeFields((array) ($feature['output']['fields'] ?? $fallback['output']['fields'] ?? [])),
            ],
            'auth' => [
                'required' => (bool) ($auth['required'] ?? true),
                'strategies' => $this->normalizeStringList((array) ($auth['strategies'] ?? ['bearer'])),
                'permissions' => $this->normalizeStringList((array) ($auth['permissions'] ?? [])),
            ],
            'database' => [
                'reads' => $this->normalizeStringList((array) ($database['reads'] ?? [])),
                'writes' => $this->normalizeStringList((array) ($database['writes'] ?? [])),
                'queries' => $this->normalizeStringList((array) ($database['queries'] ?? [])),
                'transactions' => (string) ($database['transactions'] ?? 'required'),
            ],
            'cache' => [
                'reads' => $this->normalizeStringList((array) ($cache['reads'] ?? [])),
                'writes' => $this->normalizeStringList((array) ($cache['writes'] ?? [])),
                'invalidate' => $this->normalizeStringList((array) ($cache['invalidate'] ?? [])),
            ],
            'events' => [
                'emit' => $this->normalizeStringList((array) ($events['emit'] ?? [])),
                'subscribe' => $this->normalizeStringList((array) ($events['subscribe'] ?? [])),
            ],
            'jobs' => [
                'dispatch' => $this->normalizeStringList((array) ($jobs['dispatch'] ?? [])),
            ],
            'tests' => [
                'required' => $this->normalizeStringList((array) ($tests['required'] ?? ['contract', 'feature', 'auth'])),
            ],
            'llm' => [
                'editable' => true,
                'risk_level' => 'medium',
                'notes_file' => 'prompts.md',
            ],
        ];
    }

    /**
     * @param array<string,mixed> $workflow
     * @return array<string,mixed>|null
     */
    private function normalizeWorkflow(array $workflow): ?array
    {
        $name = Str::toSnakeCase((string) ($workflow['name'] ?? ''));
        $definition = is_array($workflow['definition'] ?? null) ? $workflow['definition'] : [];
        $resource = trim((string) ($definition['resource'] ?? ''));
        if ($name === '' || $resource === '') {
            return null;
        }

        $states = array_values(array_map('strval', (array) ($definition['states'] ?? [])));
        $states = array_values(array_unique(array_filter($states, static fn(string $state): bool => $state !== '')));
        sort($states);

        $transitions = is_array($definition['transitions'] ?? null) ? $definition['transitions'] : [];
        ksort($transitions);

        return [
            'name' => $name,
            'definition' => [
                'resource' => $resource,
                'states' => $states,
                'transitions' => $transitions,
            ],
        ];
    }

    /**
     * @param array<string,mixed> $bundle
     */
    private function resourceFromBundle(array $bundle): ?string
    {
        $nodes = is_array($bundle['context_bundle']['nodes'] ?? null) ? $bundle['context_bundle']['nodes'] : [];
        foreach ($nodes as $node) {
            if (!is_array($node)) {
                continue;
            }

            $payload = is_array($node['payload'] ?? null) ? $node['payload'] : [];
            $route = is_array($payload['route'] ?? null) ? $payload['route'] : [];
            $path = trim((string) ($route['path'] ?? ''));
            if ($path === '') {
                continue;
            }

            $segments = array_values(array_filter(explode('/', trim($path, '/')), static fn(string $segment): bool => $segment !== '' && !str_starts_with($segment, '{')));
            if ($segments !== []) {
                return Str::toSnakeCase(end($segments) ?: 'items');
            }
        }

        return null;
    }

    private function resourceFromInstruction(string $instruction): ?string
    {
        if (preg_match('/\b(?:for|to|on)\s+([a-z0-9_]+)\b/i', strtolower($instruction), $matches) === 1) {
            return Str::toSnakeCase((string) ($matches[1] ?? ''));
        }

        return null;
    }

    private function actionFromInstruction(string $instruction): string
    {
        $lower = strtolower($instruction);

        foreach ([
            'bookmark',
            'publish',
            'review',
            'approve',
            'reject',
            'delete',
            'remove',
            'update',
            'edit',
            'create',
            'submit',
            'list',
            'view',
            'fetch',
            'get',
            'start',
        ] as $keyword) {
            if (str_contains($lower, $keyword)) {
                return match ($keyword) {
                    'remove' => 'delete',
                    'edit' => 'update',
                    'fetch', 'get' => 'view',
                    'submit' => 'create',
                    default => $keyword,
                };
            }
        }

        if (str_contains($lower, 'add ')) {
            return 'create';
        }

        return 'create';
    }

    /**
     * @return array<string,mixed>
     */
    private function routeForAction(string $action, string $resourcePlural): array
    {
        return match ($action) {
            'list' => ['method' => 'GET', 'path' => '/' . $resourcePlural],
            'view' => ['method' => 'GET', 'path' => '/' . $resourcePlural . '/{id}'],
            'update' => ['method' => 'PATCH', 'path' => '/' . $resourcePlural . '/{id}'],
            'delete' => ['method' => 'DELETE', 'path' => '/' . $resourcePlural . '/{id}'],
            'create' => ['method' => 'POST', 'path' => '/' . $resourcePlural],
            default => ['method' => 'POST', 'path' => '/' . $resourcePlural . '/{id}/' . $action],
        };
    }

    /**
     * @return array<string,array<string,mixed>>
     */
    private function inputFields(string $action, array $route): array
    {
        $fields = [];

        if (str_contains((string) ($route['path'] ?? ''), '{id}')) {
            $fields['id'] = ['type' => 'string', 'required' => true];
        }

        if ($action === 'create') {
            $fields['title'] = ['type' => 'string', 'required' => true];
            $fields['content'] = ['type' => 'string', 'required' => false];
        } elseif (in_array($action, ['approve', 'reject', 'review'], true)) {
            $fields['comment'] = ['type' => 'string', 'required' => false];
        }

        if ($fields === []) {
            $fields['request_id'] = ['type' => 'string', 'required' => false];
        }

        ksort($fields);

        return $fields;
    }

    /**
     * @return array<string,array<string,mixed>>
     */
    private function outputFields(bool $mutating, array $route): array
    {
        $fields = [
            'status' => ['type' => 'string', 'required' => true],
        ];

        if ($mutating || str_contains((string) ($route['path'] ?? ''), '{id}')) {
            $fields['id'] = ['type' => 'string', 'required' => true];
        }

        ksort($fields);

        return $fields;
    }

    private function shouldGenerateWorkflow(string $instruction): bool
    {
        $lower = strtolower($instruction);

        return str_contains($lower, 'workflow')
            || str_contains($lower, 'approval')
            || str_contains($lower, 'review flow');
    }

    private function featureName(string $action, string $resourceSingular, string $resourcePlural): string
    {
        return match ($action) {
            'list' => 'list_' . $resourcePlural,
            'view' => 'view_' . $resourceSingular,
            default => $action . '_' . $resourceSingular,
        };
    }

    private function singularize(string $value): string
    {
        if (str_ends_with($value, 'ies') && strlen($value) > 3) {
            return substr($value, 0, -3) . 'y';
        }

        if (str_ends_with($value, 's') && strlen($value) > 1) {
            return substr($value, 0, -1);
        }

        return $value;
    }

    private function pluralize(string $value): string
    {
        if (str_ends_with($value, 's')) {
            return $value;
        }

        if (str_ends_with($value, 'y') && strlen($value) > 1) {
            return substr($value, 0, -1) . 'ies';
        }

        return $value . 's';
    }

    private function pastTense(string $action): string
    {
        return match ($action) {
            'create' => 'created',
            'update' => 'updated',
            'delete' => 'deleted',
            'publish' => 'published',
            'review' => 'reviewed',
            'approve' => 'approved',
            'reject' => 'rejected',
            'bookmark' => 'bookmarked',
            default => $action . 'ed',
        };
    }

    /**
     * @param array<string,mixed> $fields
     * @return array<string,array<string,mixed>>
     */
    private function normalizeFields(array $fields): array
    {
        $normalized = [];
        foreach ($fields as $name => $field) {
            if (!is_string($name) || !is_array($field)) {
                continue;
            }

            $normalized[Str::toSnakeCase($name)] = [
                'type' => (string) ($field['type'] ?? 'string'),
                'required' => (bool) ($field['required'] ?? false),
            ];
        }

        ksort($normalized);

        return $normalized;
    }

    /**
     * @param array<int,mixed> $values
     * @return array<int,string>
     */
    private function normalizeStringList(array $values): array
    {
        $normalized = array_values(array_unique(array_filter(
            array_map(static fn(mixed $value): string => trim((string) $value), $values),
            static fn(string $value): bool => $value !== '',
        )));
        sort($normalized);

        return $normalized;
    }
}
