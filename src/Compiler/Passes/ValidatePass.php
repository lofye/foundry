<?php
declare(strict_types=1);

namespace Foundry\Compiler\Passes;

use Foundry\Compiler\CompilationState;
use Foundry\Compiler\CompilerPass;

final class ValidatePass implements CompilerPass
{
    /**
     * @var array<int,string>
     */
    private array $allowedKinds = [
        'http',
        'job',
        'event_handler',
        'scheduled',
        'webhook_incoming',
        'webhook_outgoing',
        'ai_task',
    ];

    /**
     * @var array<int,string>
     */
    private array $allowedAuthStrategies = ['bearer', 'session', 'api_key'];

    public function name(): string
    {
        return 'validate';
    }

    public function run(CompilationState $state): void
    {
        foreach ($state->graph->nodesByType('feature') as $node) {
            $payload = $node->payload();
            $feature = (string) ($payload['feature'] ?? '');
            $nodeId = 'feature:' . $feature;
            $sourcePath = (string) ($payload['manifest_path'] ?? $node->sourcePath());

            $kind = (string) ($payload['kind'] ?? '');
            if (!in_array($kind, $this->allowedKinds, true)) {
                $state->diagnostics->error(
                    code: 'FDY1002_INVALID_FEATURE_KIND',
                    category: 'validation',
                    message: 'Feature kind is invalid: ' . $kind,
                    nodeId: $nodeId,
                    sourcePath: $sourcePath,
                    suggestedFix: 'Use one of: ' . implode(', ', $this->allowedKinds),
                    pass: $this->name(),
                );
            }

            $route = is_array($payload['route'] ?? null) ? $payload['route'] : null;
            if ($kind === 'http' && $route === null) {
                $state->diagnostics->error(
                    code: 'FDY1003_HTTP_ROUTE_REQUIRED',
                    category: 'routing',
                    message: 'HTTP feature is missing route definition.',
                    nodeId: $nodeId,
                    sourcePath: $sourcePath,
                    suggestedFix: 'Add route.method and route.path to feature.yaml.',
                    pass: $this->name(),
                );
            }

            if ($route !== null) {
                $path = (string) ($route['path'] ?? '');
                if ($path === '' || !str_starts_with($path, '/')) {
                    $state->diagnostics->error(
                        code: 'FDY1004_ROUTE_PATH_INVALID',
                        category: 'routing',
                        message: 'Route path must be absolute and start with /.',
                        nodeId: $nodeId,
                        sourcePath: $sourcePath,
                        pass: $this->name(),
                    );
                }
            }

            $auth = is_array($payload['auth'] ?? null) ? $payload['auth'] : [];
            $strategies = array_values(array_map('strval', (array) ($auth['strategies'] ?? [])));
            foreach ($strategies as $strategy) {
                if (!in_array($strategy, $this->allowedAuthStrategies, true)) {
                    $state->diagnostics->error(
                        code: 'FDY1005_AUTH_STRATEGY_UNKNOWN',
                        category: 'auth',
                        message: 'Unknown auth strategy: ' . $strategy,
                        nodeId: 'auth:' . $feature,
                        sourcePath: $sourcePath,
                        pass: $this->name(),
                    );
                }
            }

            $declaredPermissions = array_values(array_map('strval', (array) ($payload['permissions']['declared'] ?? [])));
            $referencedPermissions = array_values(array_map('strval', (array) ($auth['permissions'] ?? [])));
            foreach ($referencedPermissions as $permission) {
                if (!in_array($permission, $declaredPermissions, true)) {
                    $state->diagnostics->error(
                        code: 'FDY1006_PERMISSION_REFERENCE_MISSING',
                        category: 'permissions',
                        message: 'Referenced permission is not declared in permissions.yaml: ' . $permission,
                        nodeId: $nodeId,
                        sourcePath: $sourcePath,
                        pass: $this->name(),
                    );
                }
            }

            $jobsDispatch = array_values(array_map('strval', (array) ($payload['jobs']['dispatch'] ?? [])));
            $jobDefinitions = array_keys((array) ($payload['jobs']['definitions'] ?? []));
            foreach ($jobsDispatch as $jobName) {
                if (!in_array($jobName, $jobDefinitions, true)) {
                    $state->diagnostics->error(
                        code: 'FDY1007_JOB_REFERENCE_UNKNOWN',
                        category: 'jobs',
                        message: 'Feature dispatches unknown job: ' . $jobName,
                        nodeId: $nodeId,
                        sourcePath: $sourcePath,
                        pass: $this->name(),
                    );
                }
            }

            $cacheInvalidations = array_values(array_map('strval', (array) ($payload['cache']['invalidate'] ?? [])));
            $cacheEntries = array_keys((array) ($payload['cache']['entries'] ?? []));
            foreach ($cacheInvalidations as $cacheKey) {
                if (!in_array($cacheKey, $cacheEntries, true)) {
                    $state->diagnostics->warning(
                        code: 'FDY1008_CACHE_REFERENCE_UNKNOWN',
                        category: 'cache',
                        message: 'Feature invalidates cache key without local entry definition: ' . $cacheKey,
                        nodeId: $nodeId,
                        sourcePath: $sourcePath,
                        pass: $this->name(),
                    );
                }
            }

            $csrf = is_array($payload['csrf'] ?? null) ? $payload['csrf'] : [];
            if ((bool) ($csrf['required'] ?? false) && $kind !== 'http') {
                $state->diagnostics->warning(
                    code: 'FDY1010_CSRF_ON_NON_HTTP_FEATURE',
                    category: 'validation',
                    message: 'CSRF guard is configured on a non-http feature.',
                    nodeId: $nodeId,
                    sourcePath: $sourcePath,
                    pass: $this->name(),
                );
            }

            $resource = is_array($payload['resource'] ?? null) ? $payload['resource'] : [];
            if ($resource !== [] && !isset($resource['name'])) {
                $state->diagnostics->warning(
                    code: 'FDY1011_RESOURCE_METADATA_INCOMPLETE',
                    category: 'validation',
                    message: 'Resource metadata exists but is missing resource.name.',
                    nodeId: $nodeId,
                    sourcePath: $sourcePath,
                    pass: $this->name(),
                );
            }
        }

        foreach ($state->graph->nodesByType('route') as $routeNode) {
            $features = array_values(array_map('strval', (array) ($routeNode->payload()['features'] ?? [])));
            if (count($features) > 1) {
                $state->diagnostics->error(
                    code: 'FDY1001_DUPLICATE_ROUTE',
                    category: 'routing',
                    message: 'Duplicate route detected for ' . (string) ($routeNode->payload()['signature'] ?? $routeNode->id()) . '.',
                    nodeId: $routeNode->id(),
                    sourcePath: $routeNode->sourcePath(),
                    relatedNodes: array_map(static fn (string $feature): string => 'feature:' . $feature, $features),
                    suggestedFix: 'Rename or remove one of the conflicting routes.',
                    pass: $this->name(),
                );
            }
        }

        foreach ($state->graph->nodesByType('schema') as $schemaNode) {
            $document = $schemaNode->payload()['document'] ?? null;
            if (!is_array($document)) {
                $state->diagnostics->error(
                    code: 'FDY1101_SCHEMA_NOT_FOUND_OR_INVALID',
                    category: 'schemas',
                    message: 'Schema file missing or invalid: ' . (string) ($schemaNode->payload()['path'] ?? $schemaNode->sourcePath()),
                    nodeId: $schemaNode->id(),
                    sourcePath: $schemaNode->sourcePath(),
                    pass: $this->name(),
                );
            }
        }

        foreach ($state->graph->nodesByType('query') as $queryNode) {
            $defined = (bool) ($queryNode->payload()['defined'] ?? false);
            $referenced = (bool) ($queryNode->payload()['referenced'] ?? false);

            if ($referenced && !$defined) {
                $state->diagnostics->error(
                    code: 'FDY1201_QUERY_REFERENCE_MISSING',
                    category: 'queries',
                    message: 'Feature references query that is not defined in queries.sql: ' . (string) ($queryNode->payload()['name'] ?? ''),
                    nodeId: $queryNode->id(),
                    sourcePath: $queryNode->sourcePath(),
                    pass: $this->name(),
                );
            }

            if ($defined && !$referenced) {
                $state->diagnostics->info(
                    code: 'FDY1202_QUERY_UNUSED',
                    category: 'queries',
                    message: 'Query defined but not referenced by feature manifest: ' . (string) ($queryNode->payload()['name'] ?? ''),
                    nodeId: $queryNode->id(),
                    sourcePath: $queryNode->sourcePath(),
                    pass: $this->name(),
                );
            }
        }

        foreach ($state->graph->nodesByType('event') as $eventNode) {
            $emitters = array_values(array_map('strval', (array) ($eventNode->payload()['emitters'] ?? [])));
            $subscribers = array_values(array_map('strval', (array) ($eventNode->payload()['subscribers'] ?? [])));
            $eventName = (string) ($eventNode->payload()['name'] ?? $eventNode->id());

            if ($subscribers !== [] && $emitters === []) {
                $state->diagnostics->warning(
                    code: 'FDY1301_EVENT_SUBSCRIBE_UNKNOWN',
                    category: 'events',
                    message: 'Event has subscribers but no emitters: ' . $eventName,
                    nodeId: $eventNode->id(),
                    sourcePath: $eventNode->sourcePath(),
                    pass: $this->name(),
                );
            }

            if ($emitters !== [] && $subscribers === []) {
                $state->diagnostics->info(
                    code: 'FDY1302_EVENT_NO_SUBSCRIBERS',
                    category: 'events',
                    message: 'Event has no subscribers: ' . $eventName,
                    nodeId: $eventNode->id(),
                    sourcePath: $eventNode->sourcePath(),
                    pass: $this->name(),
                );
            }
        }

        foreach ($state->graph->nodesByType('job') as $jobNode) {
            $features = array_values(array_map('strval', (array) ($jobNode->payload()['features'] ?? [])));
            if (count($features) > 1) {
                $state->diagnostics->warning(
                    code: 'FDY1401_JOB_NAME_REUSED',
                    category: 'jobs',
                    message: 'Job name is defined by multiple features: ' . (string) ($jobNode->payload()['name'] ?? $jobNode->id()),
                    nodeId: $jobNode->id(),
                    sourcePath: $jobNode->sourcePath(),
                    pass: $this->name(),
                );
            }
        }

        foreach ($state->graph->nodesByType('permission') as $permissionNode) {
            $referencedBy = array_values(array_map('strval', (array) ($permissionNode->payload()['referenced_by'] ?? [])));
            if ($referencedBy === []) {
                $state->diagnostics->info(
                    code: 'FDY1501_PERMISSION_UNUSED',
                    category: 'permissions',
                    message: 'Permission is declared but not referenced by auth config: ' . (string) ($permissionNode->payload()['name'] ?? ''),
                    nodeId: $permissionNode->id(),
                    sourcePath: $permissionNode->sourcePath(),
                    pass: $this->name(),
                );
            }
        }

        foreach ($state->graph->nodesByType('context_manifest') as $contextNode) {
            if (!is_array($contextNode->payload()['document'] ?? null)) {
                $state->diagnostics->warning(
                    code: 'FDY1601_CONTEXT_MANIFEST_MISSING',
                    category: 'graph',
                    message: 'Context manifest is missing; regenerate context manifest to improve inspection quality.',
                    nodeId: $contextNode->id(),
                    sourcePath: $contextNode->sourcePath(),
                    pass: $this->name(),
                );
            }
        }
    }
}
