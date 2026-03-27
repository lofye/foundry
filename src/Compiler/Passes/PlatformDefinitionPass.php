<?php

declare(strict_types=1);

namespace Foundry\Compiler\Passes;

use Foundry\Compiler\CompilationState;
use Foundry\Compiler\CompilerPass;
use Foundry\Compiler\GraphEdge;
use Foundry\Compiler\IR\BillingNode;
use Foundry\Compiler\IR\InspectUiNode;
use Foundry\Compiler\IR\LocaleBundleNode;
use Foundry\Compiler\IR\OrchestrationNode;
use Foundry\Compiler\IR\PolicyNode;
use Foundry\Compiler\IR\RoleNode;
use Foundry\Compiler\IR\SearchIndexNode;
use Foundry\Compiler\IR\StreamNode;
use Foundry\Compiler\IR\WorkflowNode;

final class PlatformDefinitionPass implements CompilerPass
{
    public function name(): string
    {
        return 'platform_definitions';
    }

    public function run(CompilationState $state): void
    {
        $definitions = $state->discoveredDefinitions;
        if ($definitions === []) {
            return;
        }

        $knownRoles = $this->processRoles($state, (array) ($definitions['roles'] ?? []));
        $this->processPolicies($state, (array) ($definitions['policy'] ?? []), $knownRoles);

        $this->processBilling($state, (array) ($definitions['billing'] ?? []));
        $this->processWorkflows($state, (array) ($definitions['workflow'] ?? []));
        $this->processOrchestrations($state, (array) ($definitions['orchestration'] ?? []));
        $this->processSearchIndexes($state, (array) ($definitions['search_index'] ?? []));
        $this->processStreams($state, (array) ($definitions['stream'] ?? []));
        $this->processLocales($state, (array) ($definitions['locale_bundle'] ?? []));
        $this->processInspectUi($state, (array) ($definitions['inspect_ui'] ?? []));
    }

    /**
     * @param array<string,array<string,mixed>> $rows
     */
    private function processBilling(CompilationState $state, array $rows): void
    {
        foreach ($this->sortedRows($rows) as $row) {
            $document = is_array($row['document'] ?? null) ? $row['document'] : [];
            $sourcePath = (string) ($row['path'] ?? '');
            $provider = strtolower((string) ($document['provider'] ?? ($row['name'] ?? 'stripe')));
            if ($provider === '') {
                continue;
            }

            $version = (int) ($document['version'] ?? 1);
            if ($version !== 1) {
                $state->diagnostics->error(
                    code: 'FDY2401_BILLING_DEFINITION_VERSION_UNSUPPORTED',
                    category: 'migrations',
                    message: sprintf('Unsupported billing definition version %d for provider %s.', $version, $provider),
                    nodeId: 'billing:' . $provider,
                    sourcePath: $sourcePath,
                    suggestedFix: 'Set version: 1 or run migrations.',
                    pass: $this->name(),
                );
            }

            if ($provider !== 'stripe') {
                $state->diagnostics->warning(
                    code: 'FDY2402_BILLING_PROVIDER_UNSUPPORTED',
                    category: 'billing',
                    message: sprintf('Provider %s is not fully supported yet; defaulting to stripe semantics.', $provider),
                    nodeId: 'billing:' . $provider,
                    sourcePath: $sourcePath,
                    pass: $this->name(),
                );
            }

            $plans = [];
            $seenPlanKeys = [];
            foreach ((array) ($document['plans'] ?? []) as $index => $plan) {
                if (!is_array($plan)) {
                    continue;
                }
                $key = (string) ($plan['key'] ?? 'plan_' . $index);
                if ($key === '') {
                    continue;
                }

                if (isset($seenPlanKeys[$key])) {
                    $state->diagnostics->error(
                        code: 'FDY2403_BILLING_PLAN_DUPLICATE',
                        category: 'billing',
                        message: sprintf('Duplicate billing plan key %s detected.', $key),
                        nodeId: 'billing:' . $provider,
                        sourcePath: $sourcePath,
                        pass: $this->name(),
                    );
                    continue;
                }
                $seenPlanKeys[$key] = true;

                $priceId = (string) ($plan['price_id'] ?? '');
                if ($priceId === '') {
                    $state->diagnostics->error(
                        code: 'FDY2404_BILLING_PRICE_ID_MISSING',
                        category: 'billing',
                        message: sprintf('Billing plan %s is missing price_id.', $key),
                        nodeId: 'billing:' . $provider,
                        sourcePath: $sourcePath,
                        pass: $this->name(),
                    );
                }

                $plans[$key] = [
                    'key' => $key,
                    'display_name' => (string) ($plan['display_name'] ?? $key),
                    'price_id' => $priceId,
                    'interval' => (string) ($plan['interval'] ?? 'month'),
                    'trial_days' => (int) ($plan['trial_days'] ?? 0),
                ];
            }
            ksort($plans);

            $featureMap = [
                'checkout' => 'create_checkout_session',
                'portal' => 'view_billing_portal',
                'webhook' => 'handle_billing_webhook',
                'invoices' => 'list_invoices',
                'subscription' => 'view_current_subscription',
            ];
            foreach ((array) ($document['feature_names'] ?? []) as $key => $value) {
                if (!is_string($key)) {
                    continue;
                }
                $featureName = (string) $value;
                if ($featureName !== '') {
                    $featureMap[$key] = $featureName;
                }
            }
            ksort($featureMap);

            $nodeId = 'billing:' . $provider;
            $state->graph->addNode(new BillingNode(
                id: $nodeId,
                sourcePath: $sourcePath,
                payload: [
                    'provider' => $provider,
                    'version' => 1,
                    'plans' => $plans,
                    'feature_map' => $featureMap,
                    'webhook_signing_secret_env' => (string) ($document['webhook_signing_secret_env'] ?? 'STRIPE_WEBHOOK_SECRET'),
                ],
                sourceRegion: ['line_start' => 1, 'line_end' => null],
                graphCompatibility: [1],
            ));

            foreach ($featureMap as $feature) {
                $featureId = 'feature:' . $feature;
                if (!$state->graph->hasNode($featureId)) {
                    $state->diagnostics->warning(
                        code: 'FDY2405_BILLING_FEATURE_MISSING',
                        category: 'linking',
                        message: sprintf('Billing provider %s references missing feature %s.', $provider, $feature),
                        nodeId: $nodeId,
                        sourcePath: $sourcePath,
                        pass: $this->name(),
                    );
                    continue;
                }

                $state->graph->addEdge(GraphEdge::make('billing_to_feature', $nodeId, $featureId));
                $state->graph->addEdge(GraphEdge::make('feature_to_billing', $featureId, $nodeId));
            }
        }
    }

    /**
     * @param array<string,array<string,mixed>> $rows
     */
    private function processWorkflows(CompilationState $state, array $rows): void
    {
        foreach ($this->sortedRows($rows) as $row) {
            $document = is_array($row['document'] ?? null) ? $row['document'] : [];
            $sourcePath = (string) ($row['path'] ?? '');
            $resource = (string) ($document['resource'] ?? ($row['name'] ?? ''));
            if ($resource === '') {
                continue;
            }

            $version = (int) ($document['version'] ?? 1);
            if ($version !== 1) {
                $state->diagnostics->error(
                    code: 'FDY2410_WORKFLOW_DEFINITION_VERSION_UNSUPPORTED',
                    category: 'migrations',
                    message: sprintf('Unsupported workflow definition version %d for %s.', $version, $resource),
                    nodeId: 'workflow:' . $resource,
                    sourcePath: $sourcePath,
                    pass: $this->name(),
                );
            }

            $states = $this->sortedStrings((array) ($document['states'] ?? []));
            if ($states === []) {
                $state->diagnostics->error(
                    code: 'FDY2411_WORKFLOW_STATES_EMPTY',
                    category: 'workflows',
                    message: sprintf('Workflow %s must define at least one state.', $resource),
                    nodeId: 'workflow:' . $resource,
                    sourcePath: $sourcePath,
                    pass: $this->name(),
                );
            }

            $stateSet = array_flip($states);
            $transitions = [];
            foreach ((array) ($document['transitions'] ?? []) as $name => $definition) {
                if (!is_string($name) || $name === '' || !is_array($definition)) {
                    continue;
                }

                $from = $this->sortedStrings((array) ($definition['from'] ?? []));
                $to = (string) ($definition['to'] ?? '');
                $permission = (string) ($definition['permission'] ?? '');
                $emit = $this->sortedStrings((array) ($definition['emit'] ?? []));

                foreach ($from as $fromState) {
                    if (!isset($stateSet[$fromState])) {
                        $state->diagnostics->error(
                            code: 'FDY2412_WORKFLOW_TRANSITION_FROM_INVALID',
                            category: 'workflows',
                            message: sprintf('Workflow %s transition %s references unknown from-state %s.', $resource, $name, $fromState),
                            nodeId: 'workflow:' . $resource,
                            sourcePath: $sourcePath,
                            pass: $this->name(),
                        );
                    }
                }

                if ($to !== '' && !isset($stateSet[$to])) {
                    $state->diagnostics->error(
                        code: 'FDY2413_WORKFLOW_TRANSITION_TO_INVALID',
                        category: 'workflows',
                        message: sprintf('Workflow %s transition %s references unknown to-state %s.', $resource, $name, $to),
                        nodeId: 'workflow:' . $resource,
                        sourcePath: $sourcePath,
                        pass: $this->name(),
                    );
                }

                $transitions[$name] = [
                    'from' => $from,
                    'to' => $to,
                    'permission' => $permission,
                    'emit' => $emit,
                ];
            }
            ksort($transitions);

            $nodeId = 'workflow:' . $resource;
            $state->graph->addNode(new WorkflowNode(
                id: $nodeId,
                sourcePath: $sourcePath,
                payload: [
                    'resource' => $resource,
                    'version' => 1,
                    'states' => $states,
                    'transitions' => $transitions,
                ],
                sourceRegion: ['line_start' => 1, 'line_end' => null],
                graphCompatibility: [1],
            ));

            foreach ($transitions as $transition) {
                $permission = (string) ($transition['permission'] ?? '');
                if ($permission !== '') {
                    $permissionId = 'permission:' . $permission;
                    if ($state->graph->hasNode($permissionId)) {
                        $state->graph->addEdge(GraphEdge::make('workflow_to_permission', $nodeId, $permissionId));
                    } else {
                        $state->diagnostics->warning(
                            code: 'FDY2414_WORKFLOW_PERMISSION_MISSING',
                            category: 'permissions',
                            message: sprintf('Workflow %s references missing permission %s.', $resource, $permission),
                            nodeId: $nodeId,
                            sourcePath: $sourcePath,
                            pass: $this->name(),
                        );
                    }
                }

                foreach ((array) ($transition['emit'] ?? []) as $event) {
                    $eventName = (string) $event;
                    if ($eventName === '') {
                        continue;
                    }

                    $eventId = 'event:' . $eventName;
                    if ($state->graph->hasNode($eventId)) {
                        $state->graph->addEdge(GraphEdge::make('workflow_to_event_emit', $nodeId, $eventId));
                    } else {
                        $state->diagnostics->warning(
                            code: 'FDY2415_WORKFLOW_EVENT_MISSING',
                            category: 'events',
                            message: sprintf('Workflow %s emits unknown event %s.', $resource, $eventName),
                            nodeId: $nodeId,
                            sourcePath: $sourcePath,
                            pass: $this->name(),
                        );
                    }
                }
            }
        }
    }

    /**
     * @param array<string,array<string,mixed>> $rows
     */
    private function processOrchestrations(CompilationState $state, array $rows): void
    {
        foreach ($this->sortedRows($rows) as $row) {
            $document = is_array($row['document'] ?? null) ? $row['document'] : [];
            $sourcePath = (string) ($row['path'] ?? '');
            $name = (string) ($document['name'] ?? ($row['name'] ?? ''));
            if ($name === '') {
                continue;
            }

            $version = (int) ($document['version'] ?? 1);
            if ($version !== 1) {
                $state->diagnostics->error(
                    code: 'FDY2420_ORCHESTRATION_DEFINITION_VERSION_UNSUPPORTED',
                    category: 'migrations',
                    message: sprintf('Unsupported orchestration definition version %d for %s.', $version, $name),
                    nodeId: 'orchestration:' . $name,
                    sourcePath: $sourcePath,
                    pass: $this->name(),
                );
            }

            $steps = [];
            $stepNames = [];
            foreach ((array) ($document['steps'] ?? []) as $definition) {
                if (!is_array($definition)) {
                    continue;
                }

                $stepName = (string) ($definition['name'] ?? '');
                if ($stepName === '') {
                    continue;
                }

                if (isset($stepNames[$stepName])) {
                    $state->diagnostics->error(
                        code: 'FDY2421_ORCHESTRATION_STEP_DUPLICATE',
                        category: 'orchestration',
                        message: sprintf('Orchestration %s has duplicate step %s.', $name, $stepName),
                        nodeId: 'orchestration:' . $name,
                        sourcePath: $sourcePath,
                        pass: $this->name(),
                    );
                    continue;
                }
                $stepNames[$stepName] = true;

                $job = (string) ($definition['job'] ?? '');
                if ($job === '') {
                    $state->diagnostics->error(
                        code: 'FDY2422_ORCHESTRATION_JOB_MISSING',
                        category: 'orchestration',
                        message: sprintf('Orchestration %s step %s is missing job.', $name, $stepName),
                        nodeId: 'orchestration:' . $name,
                        sourcePath: $sourcePath,
                        pass: $this->name(),
                    );
                }

                $steps[$stepName] = [
                    'name' => $stepName,
                    'job' => $job,
                    'depends_on' => $this->sortedStrings((array) ($definition['depends_on'] ?? [])),
                    'retry' => is_array($definition['retry'] ?? null) ? $definition['retry'] : [],
                ];
            }
            ksort($steps);

            foreach ($steps as $stepName => $step) {
                foreach ((array) ($step['depends_on'] ?? []) as $dependency) {
                    $dependencyName = (string) $dependency;
                    if ($dependencyName === '' || isset($steps[$dependencyName])) {
                        continue;
                    }

                    $state->diagnostics->error(
                        code: 'FDY2423_ORCHESTRATION_DEPENDENCY_UNKNOWN',
                        category: 'orchestration',
                        message: sprintf('Orchestration %s step %s depends on unknown step %s.', $name, $stepName, $dependencyName),
                        nodeId: 'orchestration:' . $name,
                        sourcePath: $sourcePath,
                        pass: $this->name(),
                    );
                }
            }

            if ($this->hasDependencyCycle($steps)) {
                $state->diagnostics->error(
                    code: 'FDY2424_ORCHESTRATION_CYCLE',
                    category: 'orchestration',
                    message: sprintf('Orchestration %s contains circular step dependencies.', $name),
                    nodeId: 'orchestration:' . $name,
                    sourcePath: $sourcePath,
                    pass: $this->name(),
                );
            }

            $nodeId = 'orchestration:' . $name;
            $state->graph->addNode(new OrchestrationNode(
                id: $nodeId,
                sourcePath: $sourcePath,
                payload: [
                    'name' => $name,
                    'version' => 1,
                    'steps' => array_values($steps),
                ],
                sourceRegion: ['line_start' => 1, 'line_end' => null],
                graphCompatibility: [1],
            ));

            foreach ($steps as $step) {
                $job = (string) ($step['job'] ?? '');
                if ($job === '') {
                    continue;
                }

                $jobId = 'job:' . $job;
                if ($state->graph->hasNode($jobId)) {
                    $state->graph->addEdge(GraphEdge::make('orchestration_to_job', $nodeId, $jobId));
                } else {
                    $state->diagnostics->warning(
                        code: 'FDY2425_ORCHESTRATION_JOB_UNKNOWN',
                        category: 'jobs',
                        message: sprintf('Orchestration %s references unknown job %s.', $name, $job),
                        nodeId: $nodeId,
                        sourcePath: $sourcePath,
                        pass: $this->name(),
                    );
                }
            }
        }
    }

    /**
     * @param array<string,array<string,mixed>> $rows
     */
    private function processSearchIndexes(CompilationState $state, array $rows): void
    {
        foreach ($this->sortedRows($rows) as $row) {
            $document = is_array($row['document'] ?? null) ? $row['document'] : [];
            $sourcePath = (string) ($row['path'] ?? '');
            $index = (string) ($document['index'] ?? ($row['name'] ?? ''));
            if ($index === '') {
                continue;
            }

            $version = (int) ($document['version'] ?? 1);
            if ($version !== 1) {
                $state->diagnostics->error(
                    code: 'FDY2430_SEARCH_DEFINITION_VERSION_UNSUPPORTED',
                    category: 'migrations',
                    message: sprintf('Unsupported search definition version %d for %s.', $version, $index),
                    nodeId: 'search_index:' . $index,
                    sourcePath: $sourcePath,
                    pass: $this->name(),
                );
            }

            $adapter = strtolower((string) ($document['adapter'] ?? 'sql'));
            if (!in_array($adapter, ['sql', 'meilisearch', 'postgres'], true)) {
                $state->diagnostics->error(
                    code: 'FDY2431_SEARCH_ADAPTER_UNSUPPORTED',
                    category: 'search',
                    message: sprintf('Search index %s uses unsupported adapter %s.', $index, $adapter),
                    nodeId: 'search_index:' . $index,
                    sourcePath: $sourcePath,
                    pass: $this->name(),
                );
                $adapter = 'sql';
            }

            $fields = $this->sortedStrings((array) ($document['fields'] ?? []));
            if ($fields === []) {
                $state->diagnostics->warning(
                    code: 'FDY2432_SEARCH_FIELDS_EMPTY',
                    category: 'search',
                    message: sprintf('Search index %s defines no fields.', $index),
                    nodeId: 'search_index:' . $index,
                    sourcePath: $sourcePath,
                    pass: $this->name(),
                );
            }

            $filters = $this->sortedStrings((array) ($document['filters'] ?? []));
            $resource = (string) ($document['resource'] ?? $index);

            $nodeId = 'search_index:' . $index;
            $state->graph->addNode(new SearchIndexNode(
                id: $nodeId,
                sourcePath: $sourcePath,
                payload: [
                    'index' => $index,
                    'version' => 1,
                    'adapter' => $adapter,
                    'resource' => $resource,
                    'source' => is_array($document['source'] ?? null) ? $document['source'] : [],
                    'fields' => $fields,
                    'filters' => $filters,
                ],
                sourceRegion: ['line_start' => 1, 'line_end' => null],
                graphCompatibility: [1],
            ));

            $resourceId = 'resource:' . $resource;
            if ($state->graph->hasNode($resourceId)) {
                $state->graph->addEdge(GraphEdge::make('search_index_to_resource', $nodeId, $resourceId));
                $state->graph->addEdge(GraphEdge::make('resource_to_search_index', $resourceId, $nodeId));
            }
        }
    }

    /**
     * @param array<string,array<string,mixed>> $rows
     */
    private function processStreams(CompilationState $state, array $rows): void
    {
        $routeOwners = [];

        foreach ($this->sortedRows($rows) as $row) {
            $document = is_array($row['document'] ?? null) ? $row['document'] : [];
            $sourcePath = (string) ($row['path'] ?? '');
            $name = (string) ($document['stream'] ?? ($row['name'] ?? ''));
            if ($name === '') {
                continue;
            }

            $version = (int) ($document['version'] ?? 1);
            if ($version !== 1) {
                $state->diagnostics->error(
                    code: 'FDY2440_STREAM_DEFINITION_VERSION_UNSUPPORTED',
                    category: 'migrations',
                    message: sprintf('Unsupported stream definition version %d for %s.', $version, $name),
                    nodeId: 'stream:' . $name,
                    sourcePath: $sourcePath,
                    pass: $this->name(),
                );
            }

            $transport = strtolower((string) ($document['transport'] ?? 'sse'));
            if ($transport !== 'sse') {
                $state->diagnostics->warning(
                    code: 'FDY2441_STREAM_TRANSPORT_UNSUPPORTED',
                    category: 'streams',
                    message: sprintf('Stream %s transport %s is not fully supported; using sse.', $name, $transport),
                    nodeId: 'stream:' . $name,
                    sourcePath: $sourcePath,
                    pass: $this->name(),
                );
                $transport = 'sse';
            }

            $routePath = (string) ($document['route']['path'] ?? ('/streams/' . $name));
            if (isset($routeOwners[$routePath])) {
                $state->diagnostics->error(
                    code: 'FDY2442_STREAM_ROUTE_CONFLICT',
                    category: 'routing',
                    message: sprintf('Stream route conflict for %s between %s and %s.', $routePath, $routeOwners[$routePath], $name),
                    nodeId: 'stream:' . $name,
                    sourcePath: $sourcePath,
                    pass: $this->name(),
                );
            }
            $routeOwners[$routePath] = $name;

            $auth = is_array($document['auth'] ?? null) ? $document['auth'] : ['required' => true, 'strategies' => ['session']];
            $publishFeatures = $this->sortedStrings((array) ($document['publish_features'] ?? []));

            $nodeId = 'stream:' . $name;
            $state->graph->addNode(new StreamNode(
                id: $nodeId,
                sourcePath: $sourcePath,
                payload: [
                    'stream' => $name,
                    'version' => 1,
                    'transport' => $transport,
                    'route' => ['method' => 'GET', 'path' => $routePath],
                    'auth' => $auth,
                    'publish_features' => $publishFeatures,
                    'payload_schema' => is_array($document['payload_schema'] ?? null) ? $document['payload_schema'] : [],
                ],
                sourceRegion: ['line_start' => 1, 'line_end' => null],
                graphCompatibility: [1],
            ));

            foreach ($publishFeatures as $feature) {
                $featureId = 'feature:' . $feature;
                if ($state->graph->hasNode($featureId)) {
                    $state->graph->addEdge(GraphEdge::make('feature_to_stream', $featureId, $nodeId));
                    $state->graph->addEdge(GraphEdge::make('stream_to_feature', $nodeId, $featureId));
                } else {
                    $state->diagnostics->warning(
                        code: 'FDY2443_STREAM_FEATURE_MISSING',
                        category: 'linking',
                        message: sprintf('Stream %s references missing publish feature %s.', $name, $feature),
                        nodeId: $nodeId,
                        sourcePath: $sourcePath,
                        pass: $this->name(),
                    );
                }
            }
        }
    }

    /**
     * @param array<string,array<string,mixed>> $rows
     */
    private function processLocales(CompilationState $state, array $rows): void
    {
        foreach ($this->sortedRows($rows) as $row) {
            $document = is_array($row['document'] ?? null) ? $row['document'] : [];
            $sourcePath = (string) ($row['path'] ?? '');
            $bundle = (string) ($document['bundle'] ?? ($row['name'] ?? 'core'));
            if ($bundle === '') {
                continue;
            }

            $version = (int) ($document['version'] ?? 1);
            if ($version !== 1) {
                $state->diagnostics->error(
                    code: 'FDY2450_LOCALE_DEFINITION_VERSION_UNSUPPORTED',
                    category: 'migrations',
                    message: sprintf('Unsupported locale definition version %d for bundle %s.', $version, $bundle),
                    nodeId: 'locale_bundle:' . $bundle,
                    sourcePath: $sourcePath,
                    pass: $this->name(),
                );
            }

            $locales = $this->sortedStrings((array) ($document['locales'] ?? []));
            $default = (string) ($document['default'] ?? 'en');
            if ($locales !== [] && !in_array($default, $locales, true)) {
                $state->diagnostics->error(
                    code: 'FDY2451_LOCALE_DEFAULT_INVALID',
                    category: 'locales',
                    message: sprintf('Locale bundle %s default %s is not in locales list.', $bundle, $default),
                    nodeId: 'locale_bundle:' . $bundle,
                    sourcePath: $sourcePath,
                    pass: $this->name(),
                );
            }

            $state->graph->addNode(new LocaleBundleNode(
                id: 'locale_bundle:' . $bundle,
                sourcePath: $sourcePath,
                payload: [
                    'bundle' => $bundle,
                    'version' => 1,
                    'default' => $default,
                    'locales' => $locales,
                    'translation_paths' => is_array($document['translation_paths'] ?? null) ? $document['translation_paths'] : [],
                ],
                sourceRegion: ['line_start' => 1, 'line_end' => null],
                graphCompatibility: [1],
            ));
        }
    }

    /**
     * @param array<string,array<string,mixed>> $rows
     * @return array<int,string>
     */
    private function processRoles(CompilationState $state, array $rows): array
    {
        $knownRoles = [];

        foreach ($this->sortedRows($rows) as $row) {
            $document = is_array($row['document'] ?? null) ? $row['document'] : [];
            $sourcePath = (string) ($row['path'] ?? '');
            $setName = (string) ($document['set'] ?? ($row['name'] ?? 'default'));

            $version = (int) ($document['version'] ?? 1);
            if ($version !== 1) {
                $state->diagnostics->error(
                    code: 'FDY2460_ROLES_DEFINITION_VERSION_UNSUPPORTED',
                    category: 'migrations',
                    message: sprintf('Unsupported roles definition version %d for set %s.', $version, $setName),
                    nodeId: 'role_set:' . $setName,
                    sourcePath: $sourcePath,
                    pass: $this->name(),
                );
            }

            foreach ((array) ($document['roles'] ?? []) as $roleName => $definition) {
                if (!is_string($roleName) || $roleName === '') {
                    continue;
                }
                if (in_array($roleName, $knownRoles, true)) {
                    $state->diagnostics->error(
                        code: 'FDY2461_ROLE_DUPLICATE',
                        category: 'roles',
                        message: sprintf('Duplicate role %s found across role definitions.', $roleName),
                        nodeId: 'role:' . $roleName,
                        sourcePath: $sourcePath,
                        pass: $this->name(),
                    );
                    continue;
                }

                $knownRoles[] = $roleName;
                $permissions = [];
                if (is_array($definition)) {
                    $permissions = $this->sortedStrings((array) ($definition['permissions'] ?? []));
                }

                $roleId = 'role:' . $roleName;
                $state->graph->addNode(new RoleNode(
                    id: $roleId,
                    sourcePath: $sourcePath,
                    payload: [
                        'set' => $setName,
                        'role' => $roleName,
                        'permissions' => $permissions,
                    ],
                    sourceRegion: ['line_start' => 1, 'line_end' => null],
                    graphCompatibility: [1],
                ));

                foreach ($permissions as $permission) {
                    $permissionId = 'permission:' . $permission;
                    if ($state->graph->hasNode($permissionId)) {
                        $state->graph->addEdge(GraphEdge::make('role_to_permission', $roleId, $permissionId));
                    } else {
                        $state->diagnostics->warning(
                            code: 'FDY2462_ROLE_PERMISSION_MISSING',
                            category: 'permissions',
                            message: sprintf('Role %s references missing permission %s.', $roleName, $permission),
                            nodeId: $roleId,
                            sourcePath: $sourcePath,
                            pass: $this->name(),
                        );
                    }
                }
            }
        }

        sort($knownRoles);

        return array_values(array_unique($knownRoles));
    }

    /**
     * @param array<string,array<string,mixed>> $rows
     * @param array<int,string> $knownRoles
     */
    private function processPolicies(CompilationState $state, array $rows, array $knownRoles): void
    {
        $knownRoleSet = array_flip($knownRoles);

        foreach ($this->sortedRows($rows) as $row) {
            $document = is_array($row['document'] ?? null) ? $row['document'] : [];
            $sourcePath = (string) ($row['path'] ?? '');
            $policy = (string) ($document['policy'] ?? ($row['name'] ?? ''));
            if ($policy === '') {
                continue;
            }

            $version = (int) ($document['version'] ?? 1);
            if ($version !== 1) {
                $state->diagnostics->error(
                    code: 'FDY2470_POLICY_DEFINITION_VERSION_UNSUPPORTED',
                    category: 'migrations',
                    message: sprintf('Unsupported policy definition version %d for %s.', $version, $policy),
                    nodeId: 'policy:' . $policy,
                    sourcePath: $sourcePath,
                    pass: $this->name(),
                );
            }

            $rules = [];
            foreach ((array) ($document['rules'] ?? []) as $roleName => $permissions) {
                if (!is_string($roleName) || $roleName === '') {
                    continue;
                }

                $permissionList = $this->sortedStrings((array) $permissions);
                $rules[$roleName] = $permissionList;

                if (!isset($knownRoleSet[$roleName])) {
                    $state->diagnostics->warning(
                        code: 'FDY2471_POLICY_ROLE_MISSING',
                        category: 'roles',
                        message: sprintf('Policy %s references unknown role %s.', $policy, $roleName),
                        nodeId: 'policy:' . $policy,
                        sourcePath: $sourcePath,
                        pass: $this->name(),
                    );
                }
            }
            ksort($rules);

            $policyId = 'policy:' . $policy;
            $state->graph->addNode(new PolicyNode(
                id: $policyId,
                sourcePath: $sourcePath,
                payload: [
                    'policy' => $policy,
                    'resource' => (string) ($document['resource'] ?? ''),
                    'rules' => $rules,
                ],
                sourceRegion: ['line_start' => 1, 'line_end' => null],
                graphCompatibility: [1],
            ));

            foreach ($rules as $roleName => $permissions) {
                $roleId = 'role:' . $roleName;
                if ($state->graph->hasNode($roleId)) {
                    $state->graph->addEdge(GraphEdge::make('policy_to_role', $policyId, $roleId));
                }

                foreach ($permissions as $permission) {
                    $permissionId = 'permission:' . $permission;
                    if ($state->graph->hasNode($permissionId)) {
                        $state->graph->addEdge(GraphEdge::make('policy_to_permission', $policyId, $permissionId));
                    }
                }
            }
        }
    }

    /**
     * @param array<string,array<string,mixed>> $rows
     */
    private function processInspectUi(CompilationState $state, array $rows): void
    {
        foreach ($this->sortedRows($rows) as $row) {
            $document = is_array($row['document'] ?? null) ? $row['document'] : [];
            $sourcePath = (string) ($row['path'] ?? '');
            $name = (string) ($document['name'] ?? ($row['name'] ?? 'dev'));
            if ($name === '') {
                continue;
            }

            $version = (int) ($document['version'] ?? 1);
            if ($version !== 1) {
                $state->diagnostics->error(
                    code: 'FDY2480_INSPECT_UI_DEFINITION_VERSION_UNSUPPORTED',
                    category: 'migrations',
                    message: sprintf('Unsupported inspect UI definition version %d for %s.', $version, $name),
                    nodeId: 'inspect_ui:' . $name,
                    sourcePath: $sourcePath,
                    pass: $this->name(),
                );
            }

            $sections = $this->sortedStrings((array) ($document['sections'] ?? [
                'features',
                'routes',
                'schemas',
                'auth',
                'jobs',
                'events',
                'caches',
            ]));

            $nodeId = 'inspect_ui:' . $name;
            $state->graph->addNode(new InspectUiNode(
                id: $nodeId,
                sourcePath: $sourcePath,
                payload: [
                    'name' => $name,
                    'version' => 1,
                    'enabled' => (bool) ($document['enabled'] ?? true),
                    'base_path' => (string) ($document['base_path'] ?? '/dev/inspect'),
                    'require_auth' => (bool) ($document['require_auth'] ?? true),
                    'sections' => $sections,
                ],
                sourceRegion: ['line_start' => 1, 'line_end' => null],
                graphCompatibility: [1],
            ));
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

    /**
     * @param array<string,array<string,mixed>> $steps
     */
    private function hasDependencyCycle(array $steps): bool
    {
        $visiting = [];
        $visited = [];

        $visit = function (string $name) use (&$visit, &$visiting, &$visited, $steps): bool {
            if (isset($visited[$name])) {
                return false;
            }
            if (isset($visiting[$name])) {
                return true;
            }

            $visiting[$name] = true;
            $dependencies = (array) ($steps[$name]['depends_on'] ?? []);
            foreach ($dependencies as $dependency) {
                $dep = (string) $dependency;
                if ($dep === '' || !isset($steps[$dep])) {
                    continue;
                }
                if ($visit($dep)) {
                    return true;
                }
            }

            unset($visiting[$name]);
            $visited[$name] = true;

            return false;
        };

        foreach (array_keys($steps) as $name) {
            if ($visit((string) $name)) {
                return true;
            }
        }

        return false;
    }
}
