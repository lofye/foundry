<?php
declare(strict_types=1);

namespace Foundry\Compiler\Analysis;

use Foundry\Compiler\ApplicationGraph;
use Foundry\Compiler\IR\GraphNode;
use Foundry\Support\Paths;

final readonly class ImpactAnalyzer
{
    public function __construct(private Paths $paths)
    {
    }

    /**
     * @return array<string,mixed>
     */
    public function reportForNode(ApplicationGraph $graph, string $nodeId): array
    {
        $node = $graph->node($nodeId);
        if ($node === null) {
            return [
                'node_id' => $nodeId,
                'missing' => true,
                'risk' => 'high',
                'affected_features' => [],
                'affected_routes' => [],
                'affected_schemas' => [],
                'affected_jobs' => [],
                'affected_events' => [],
                'affected_cache' => [],
                'affected_projections' => [],
                'recommended_verification' => ['php vendor/bin/foundry verify graph --json'],
                'recommended_tests' => [],
            ];
        }

        $impactedIds = $this->collectImpactedNodeIds($graph, $nodeId);

        $affectedFeatures = [];
        $affectedRoutes = [];
        $affectedSchemas = [];
        $affectedJobs = [];
        $affectedEvents = [];
        $affectedCache = [];
        $affectedProjections = [];

        foreach ($impactedIds as $id) {
            $current = $graph->node($id);
            if ($current === null) {
                continue;
            }

            $payload = $current->payload();
            $feature = (string) ($payload['feature'] ?? '');
            if ($feature !== '') {
                $affectedFeatures[] = $feature;
            }

            switch ($current->type()) {
                case 'route':
                    $affectedRoutes[] = (string) ($payload['signature'] ?? $id);
                    $affectedProjections[] = 'routes_index.php';
                    break;
                case 'schema':
                    $affectedSchemas[] = (string) ($payload['path'] ?? $id);
                    $affectedProjections[] = 'schema_index.php';
                    break;
                case 'job':
                    $affectedJobs[] = (string) ($payload['name'] ?? $id);
                    $affectedProjections[] = 'job_index.php';
                    break;
                case 'event':
                    $affectedEvents[] = (string) ($payload['name'] ?? $id);
                    $affectedProjections[] = 'event_index.php';
                    break;
                case 'cache':
                    $affectedCache[] = (string) ($payload['key'] ?? $id);
                    $affectedProjections[] = 'cache_index.php';
                    break;
                case 'permission':
                    $affectedProjections[] = 'permission_index.php';
                    break;
                case 'feature':
                    $affectedProjections[] = 'feature_index.php';
                    break;
                case 'scheduler':
                    $affectedProjections[] = 'scheduler_index.php';
                    break;
                case 'webhook':
                    $affectedProjections[] = 'webhook_index.php';
                    break;
                case 'query':
                    $affectedProjections[] = 'query_index.php';
                    break;
                case 'pipeline_stage':
                    $affectedProjections[] = 'pipeline_index.php';
                    break;
                case 'guard':
                    $affectedProjections[] = 'guard_index.php';
                    break;
                case 'interceptor':
                    $affectedProjections[] = 'interceptor_index.php';
                    break;
                case 'execution_plan':
                    $affectedProjections[] = 'execution_plan_index.php';
                    break;
                case 'starter_kit':
                    $affectedProjections[] = 'starter_index.php';
                    break;
                case 'resource':
                    $affectedProjections[] = 'resource_index.php';
                    break;
                case 'admin_resource':
                    $affectedProjections[] = 'admin_resource_index.php';
                    break;
                case 'upload_profile':
                    $affectedProjections[] = 'upload_profile_index.php';
                    break;
                case 'listing_config':
                    $affectedProjections[] = 'listing_index.php';
                    break;
                case 'form_definition':
                    $affectedProjections[] = 'form_index.php';
                    break;
            }
        }

        sort($affectedFeatures);
        sort($affectedRoutes);
        sort($affectedSchemas);
        sort($affectedJobs);
        sort($affectedEvents);
        sort($affectedCache);
        sort($affectedProjections);

        $affectedFeatures = array_values(array_unique($affectedFeatures));
        $affectedRoutes = array_values(array_unique($affectedRoutes));
        $affectedSchemas = array_values(array_unique($affectedSchemas));
        $affectedJobs = array_values(array_unique($affectedJobs));
        $affectedEvents = array_values(array_unique($affectedEvents));
        $affectedCache = array_values(array_unique($affectedCache));
        $affectedProjections = array_values(array_unique($affectedProjections));

        $recommendedTests = $this->recommendedTests($graph, $affectedFeatures);
        $recommendedVerification = $this->recommendedVerification($affectedProjections, $node, $affectedFeatures);

        return [
            'node_id' => $nodeId,
            'node_type' => $node->type(),
            'risk' => $this->risk($node, $affectedFeatures, $affectedProjections),
            'affected_features' => $affectedFeatures,
            'affected_routes' => $affectedRoutes,
            'affected_schemas' => $affectedSchemas,
            'affected_jobs' => $affectedJobs,
            'affected_events' => $affectedEvents,
            'affected_cache' => $affectedCache,
            'affected_projections' => $affectedProjections,
            'recommended_verification' => $recommendedVerification,
            'recommended_tests' => $recommendedTests,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public function reportForFile(ApplicationGraph $graph, string $path): array
    {
        $relative = $this->normalizePath($path);
        $matches = [];

        foreach ($graph->nodes() as $node) {
            if ($node->sourcePath() === $relative) {
                $matches[] = $node->id();
            }
        }

        sort($matches);

        if ($matches === []) {
            return [
                'file' => $relative,
                'nodes' => [],
                'risk' => 'low',
                'message' => 'No graph nodes mapped to file.',
            ];
        }

        $reports = [];
        foreach ($matches as $nodeId) {
            $reports[] = $this->reportForNode($graph, $nodeId);
        }

        $risk = 'low';
        foreach ($reports as $report) {
            $risk = $this->maxRisk($risk, (string) ($report['risk'] ?? 'low'));
        }

        return [
            'file' => $relative,
            'nodes' => $matches,
            'risk' => $risk,
            'reports' => $reports,
        ];
    }

    /**
     * @return array<int,string>
     */
    public function affectedTests(ApplicationGraph $graph, string $nodeId): array
    {
        $report = $this->reportForNode($graph, $nodeId);

        return array_values(array_map('strval', (array) ($report['recommended_tests'] ?? [])));
    }

    /**
     * @return array<int,string>
     */
    public function affectedFeatures(ApplicationGraph $graph, string $nodeId): array
    {
        $report = $this->reportForNode($graph, $nodeId);

        return array_values(array_map('strval', (array) ($report['affected_features'] ?? [])));
    }

    /**
     * @return array<int,string>
     */
    private function collectImpactedNodeIds(ApplicationGraph $graph, string $rootNodeId): array
    {
        $visited = [];
        $queue = [$rootNodeId];

        while ($queue !== []) {
            $current = array_shift($queue);
            if (!is_string($current) || isset($visited[$current])) {
                continue;
            }

            $visited[$current] = true;

            foreach ($graph->dependents($current) as $edge) {
                if (!isset($visited[$edge->from])) {
                    $queue[] = $edge->from;
                }
            }
        }

        $ids = array_keys($visited);
        sort($ids);

        return $ids;
    }

    /**
     * @param array<int,string> $features
     * @return array<int,string>
     */
    private function recommendedTests(ApplicationGraph $graph, array $features): array
    {
        $tests = [];

        foreach ($features as $feature) {
            $node = $graph->node('feature:' . $feature);
            if ($node === null) {
                continue;
            }

            $payload = $node->payload();
            $required = array_values(array_map('strval', (array) ($payload['tests']['required'] ?? [])));
            if ($required === []) {
                continue;
            }

            foreach ($required as $kind) {
                $tests[] = $feature . '_' . $kind . '_test';
            }
        }

        sort($tests);

        return array_values(array_unique($tests));
    }

    /**
     * @param array<int,string> $projections
     * @param array<int,string> $features
     * @return array<int,string>
     */
    private function recommendedVerification(array $projections, GraphNode $node, array $features): array
    {
        $commands = ['php vendor/bin/foundry verify graph --json'];

        if (in_array('permission_index.php', $projections, true) || $node->type() === 'auth') {
            $commands[] = 'php vendor/bin/foundry verify auth --json';
        }

        if (in_array('cache_index.php', $projections, true)) {
            $commands[] = 'php vendor/bin/foundry verify cache --json';
        }

        if (in_array('event_index.php', $projections, true)) {
            $commands[] = 'php vendor/bin/foundry verify events --json';
        }

        if (in_array('job_index.php', $projections, true)) {
            $commands[] = 'php vendor/bin/foundry verify jobs --json';
        }

        if (in_array('schema_index.php', $projections, true)) {
            $commands[] = 'php vendor/bin/foundry verify contracts --json';
        }

        if (
            in_array('resource_index.php', $projections, true)
            || in_array('admin_resource_index.php', $projections, true)
            || in_array('upload_profile_index.php', $projections, true)
            || in_array('listing_index.php', $projections, true)
        ) {
            $commands[] = 'php vendor/bin/foundry verify resource <name> --json';
        }

        if (
            in_array('pipeline_index.php', $projections, true)
            || in_array('guard_index.php', $projections, true)
            || in_array('interceptor_index.php', $projections, true)
            || in_array('execution_plan_index.php', $projections, true)
        ) {
            $commands[] = 'php vendor/bin/foundry verify pipeline --json';
        }

        foreach ($features as $feature) {
            $commands[] = 'php vendor/bin/foundry verify feature ' . $feature . ' --json';
        }

        $commands[] = 'php vendor/bin/phpunit';

        $commands = array_values(array_unique($commands));
        sort($commands);

        return $commands;
    }

    /**
     * @param array<int,string> $features
     * @param array<int,string> $projections
     */
    private function risk(GraphNode $node, array $features, array $projections): string
    {
        $risk = 'low';
        $type = $node->type();

        if (in_array($type, ['route', 'auth', 'rate_limit', 'pipeline_stage', 'interceptor', 'execution_plan'], true)) {
            $risk = 'high';
        }

        if ($type === 'schema') {
            $role = (string) ($node->payload()['role'] ?? '');
            $risk = ($role === 'input') ? 'high' : 'medium';
        }

        if (in_array($type, ['query', 'event', 'job', 'cache', 'feature', 'guard', 'resource', 'admin_resource', 'upload_profile', 'listing_config', 'form_definition'], true)) {
            $risk = $this->maxRisk($risk, 'medium');
        }

        if (count($features) > 3 || count($projections) > 4) {
            $risk = $this->maxRisk($risk, 'high');
        }

        return $risk;
    }

    private function maxRisk(string $a, string $b): string
    {
        $order = ['low' => 0, 'medium' => 1, 'high' => 2];

        return ($order[$a] ?? 0) >= ($order[$b] ?? 0) ? $a : $b;
    }

    private function normalizePath(string $path): string
    {
        if ($path === '') {
            return $path;
        }

        $root = rtrim($this->paths->root(), '/') . '/';
        if (str_starts_with($path, $root)) {
            return ltrim(substr($path, strlen($root)), '/');
        }

        return ltrim($path, '/');
    }
}
