<?php
declare(strict_types=1);

namespace Foundry\Compiler\Prompt;

use Foundry\Compiler\Analysis\ImpactAnalyzer;
use Foundry\Compiler\ApplicationGraph;
use Foundry\Compiler\GraphEdge;
use Foundry\Compiler\IR\GraphNode;

final readonly class GraphPromptBuilder
{
    public function __construct(
        private ImpactAnalyzer $impactAnalyzer,
        private string $commandPrefix = 'foundry',
    )
    {
    }

    /**
     * @return array<string,mixed>
     */
    public function build(ApplicationGraph $graph, string $instruction, bool $featureContext = false): array
    {
        $instruction = trim($instruction);
        $tokens = $this->tokenize($instruction);
        $selectedFeatures = $this->selectFeatures($graph, $instruction, $tokens, $featureContext);

        $nodeIds = [];
        foreach ($selectedFeatures as $feature) {
            $featureNodeId = 'feature:' . $feature;
            $nodeIds[$featureNodeId] = true;

            foreach ($graph->dependencies($featureNodeId) as $edge) {
                $nodeIds[$edge->from] = true;
                $nodeIds[$edge->to] = true;
            }

            if ($featureContext) {
                continue;
            }

            foreach ($graph->dependents($featureNodeId) as $edge) {
                $nodeIds[$edge->from] = true;
                $nodeIds[$edge->to] = true;
            }
        }

        $sortedNodeIds = array_keys($nodeIds);
        sort($sortedNodeIds);
        $nodes = [];
        foreach ($sortedNodeIds as $nodeId) {
            $node = $graph->node($nodeId);
            if (!$node instanceof GraphNode) {
                continue;
            }

            $nodes[] = [
                'id' => $node->id(),
                'type' => $node->type(),
                'source_path' => $node->sourcePath(),
                'payload' => $node->payload(),
            ];
        }

        $edges = [];
        foreach ($graph->edges() as $edge) {
            if (!$edge instanceof GraphEdge) {
                continue;
            }

            if (!isset($nodeIds[$edge->from], $nodeIds[$edge->to])) {
                continue;
            }

            $edges[] = $edge->toArray();
        }
        usort(
            $edges,
            static fn (array $a, array $b): int => strcmp((string) ($a['id'] ?? ''), (string) ($b['id'] ?? '')),
        );

        $impact = [];
        foreach ($selectedFeatures as $feature) {
            $impact[] = $this->impactAnalyzer->reportForNode($graph, 'feature:' . $feature);
        }
        usort(
            $impact,
            static fn (array $a, array $b): int => strcmp((string) ($a['node_id'] ?? ''), (string) ($b['node_id'] ?? '')),
        );

        $constraints = [
            'Edit only source-of-truth files under app/features/* and app/definitions/*.',
            'Do not hand-edit app/.foundry/build/* or app/generated/*.',
            'Preserve existing graph node IDs and naming conventions when possible.',
            'Keep manifests, schemas, queries, permissions, events, jobs, cache, and tests in sync.',
            'Respect execution pipeline stages, guards, and interceptor attachments.',
            'Prefer deterministic, minimal edits scoped to relevant features.',
        ];

        $workflow = [
            $this->commandPrefix . ' compile graph --json',
            $this->commandPrefix . ' inspect execution-plan <feature> --json',
            $this->commandPrefix . ' inspect impact --file=app/features/<feature>/feature.yaml --json',
            $this->commandPrefix . ' verify graph --json',
            $this->commandPrefix . ' verify pipeline --json',
            $this->commandPrefix . ' verify contracts --json',
            'php vendor/bin/phpunit',
        ];

        $executionPlans = $this->executionPlanContext($graph, $selectedFeatures);
        $promptText = $this->composePrompt($instruction, $selectedFeatures, $nodes, $executionPlans, $constraints, $workflow);

        $recommendedCommands = [];
        foreach ($impact as $report) {
            foreach ((array) ($report['recommended_verification'] ?? []) as $command) {
                $recommendedCommands[] = (string) $command;
            }
            foreach ((array) ($report['recommended_tests'] ?? []) as $test) {
                $recommendedCommands[] = 'php vendor/bin/phpunit --filter=' . (string) $test;
            }
        }
        foreach ($workflow as $command) {
            $recommendedCommands[] = $command;
        }
        sort($recommendedCommands);
        $recommendedCommands = array_values(array_unique($recommendedCommands));

        return [
            'instruction' => $instruction,
            'tokens' => $tokens,
            'selected_features' => $selectedFeatures,
            'context_bundle' => [
                'nodes' => $nodes,
                'edges' => $edges,
                'node_counts' => $this->nodeCounts($nodes),
                'execution_plans' => $executionPlans,
            ],
            'impact' => $impact,
            'prompt' => [
                'constraints' => $constraints,
                'workflow' => $workflow,
                'text' => $promptText,
                'correction_template' => $this->correctionTemplate(),
            ],
            'recommended_commands' => $recommendedCommands,
        ];
    }

    /**
     * @param array<int,string> $tokens
     * @return array<int,string>
     */
    private function selectFeatures(ApplicationGraph $graph, string $instruction, array $tokens, bool $featureContext): array
    {
        $scores = [];
        $instructionLower = strtolower($instruction);

        foreach ($graph->nodesByType('feature') as $featureNode) {
            $payload = $featureNode->payload();
            $feature = (string) ($payload['feature'] ?? '');
            if ($feature === '') {
                continue;
            }

            $score = 0;
            $featureLower = strtolower($feature);

            if (str_contains($instructionLower, $featureLower)) {
                $score += 8;
            }

            $route = is_array($payload['route'] ?? null) ? $payload['route'] : [];
            $routePath = strtolower((string) ($route['path'] ?? ''));
            $routeMethod = strtolower((string) ($route['method'] ?? ''));
            if ($routePath !== '' && str_contains($instructionLower, $routePath)) {
                $score += 5;
            }
            if ($routeMethod !== '' && in_array($routeMethod, $tokens, true)) {
                $score += 2;
            }

            foreach ((array) ($payload['events']['emit'] ?? []) as $event) {
                if (str_contains($instructionLower, strtolower((string) $event))) {
                    $score += 3;
                }
            }
            foreach ((array) ($payload['cache']['invalidate'] ?? []) as $cacheKey) {
                if (str_contains($instructionLower, strtolower((string) $cacheKey))) {
                    $score += 2;
                }
            }
            foreach ((array) ($payload['auth']['permissions'] ?? []) as $permission) {
                if (str_contains($instructionLower, strtolower((string) $permission))) {
                    $score += 2;
                }
            }

            if ($score > 0) {
                $scores[$feature] = $score;
            }
        }

        if ($scores === []) {
            $fallback = $graph->features();
            if (!$featureContext) {
                $fallback = array_slice($fallback, 0, 3);
            }
            sort($fallback);

            return $featureContext ? $fallback : array_slice($fallback, 0, 3);
        }

        $rows = [];
        foreach ($scores as $feature => $score) {
            $rows[] = ['feature' => (string) $feature, 'score' => (int) $score];
        }
        usort(
            $rows,
            static fn (array $a, array $b): int => ((int) ($b['score'] ?? 0) <=> (int) ($a['score'] ?? 0))
                ?: strcmp((string) ($a['feature'] ?? ''), (string) ($b['feature'] ?? '')),
        );

        $features = array_values(array_map(
            static fn (array $row): string => (string) ($row['feature'] ?? ''),
            $rows,
        ));

        return array_slice($features, 0, 5);
    }

    /**
     * @param array<int,string> $tokens
     * @return array<int,string>
     */
    private function tokenize(string $instruction): array
    {
        $tokens = preg_split('/[^a-z0-9_:\\/.-]+/i', strtolower($instruction)) ?: [];
        $tokens = array_values(array_filter(array_map('strval', $tokens), static fn (string $token): bool => $token !== ''));
        $tokens = array_values(array_unique($tokens));
        sort($tokens);

        return $tokens;
    }

    /**
     * @param array<int,array<string,mixed>> $nodes
     * @param array<int,array<string,mixed>> $executionPlans
     * @param array<int,string> $constraints
     * @param array<int,string> $workflow
     */
    private function composePrompt(string $instruction, array $selectedFeatures, array $nodes, array $executionPlans, array $constraints, array $workflow): string
    {
        $byType = $this->nodeCounts($nodes);

        $lines = [
            'Instruction:',
            (string) $instruction,
            '',
            'Relevant features: ' . ($selectedFeatures === [] ? '(none)' : implode(', ', $selectedFeatures)),
            'Context node counts: ' . json_encode($byType, JSON_UNESCAPED_SLASHES),
            '',
            'Execution plans:',
        ];

        if ($executionPlans === []) {
            $lines[] = '- (none)';
        } else {
            foreach ($executionPlans as $plan) {
                $feature = (string) ($plan['feature'] ?? '');
                $route = (string) ($plan['route_signature'] ?? '');
                $stages = implode(', ', array_values(array_map('strval', (array) ($plan['stages'] ?? []))));
                $guards = implode(', ', array_values(array_map('strval', (array) ($plan['guards'] ?? []))));
                $lines[] = '- ' . $feature . ' [' . $route . '] stages={' . $stages . '} guards={' . $guards . '}';
            }
        }

        $lines[] = '';
        $lines[] = 'Constraints:';

        foreach ($constraints as $constraint) {
            $lines[] = '- ' . $constraint;
        }

        $lines[] = '';
        $lines[] = 'Required workflow:';
        foreach ($workflow as $step) {
            $lines[] = '- ' . $step;
        }

        $lines[] = '';
        $lines[] = 'Output requirements:';
        $lines[] = '- List exact files to edit.';
        $lines[] = '- Show manifest/schema/query/auth/cache/event/job/test updates.';
        $lines[] = '- Explain how changes affect graph nodes and edges.';
        $lines[] = '- Include follow-up verification steps.';

        return implode("\n", $lines);
    }

    /**
     * @param array<int,string> $features
     * @return array<int,array<string,mixed>>
     */
    private function executionPlanContext(ApplicationGraph $graph, array $features): array
    {
        $plans = [];
        foreach ($features as $feature) {
            $plan = $graph->node('execution_plan:feature:' . $feature);
            if ($plan === null) {
                continue;
            }

            $payload = $plan->payload();
            $plans[] = [
                'feature' => (string) ($payload['feature'] ?? $feature),
                'route_signature' => (string) ($payload['route_signature'] ?? ''),
                'stages' => array_values(array_map('strval', (array) ($payload['stages'] ?? []))),
                'guards' => array_values(array_map('strval', (array) ($payload['guards'] ?? []))),
            ];
        }

        usort(
            $plans,
            static fn (array $a, array $b): int => strcmp((string) ($a['feature'] ?? ''), (string) ($b['feature'] ?? '')),
        );

        return $plans;
    }

    /**
     * @param array<int,array<string,mixed>> $nodes
     * @return array<string,int>
     */
    private function nodeCounts(array $nodes): array
    {
        $counts = [];
        foreach ($nodes as $node) {
            $type = (string) ($node['type'] ?? 'unknown');
            $counts[$type] = ($counts[$type] ?? 0) + 1;
        }
        ksort($counts);

        return $counts;
    }

    private function correctionTemplate(): string
    {
        return <<<'TXT'
The previous attempt produced diagnostics.
Use the diagnostic JSON as authoritative feedback.
Revise only the impacted source-of-truth files.
Re-run compile and verify commands after changes.
TXT;
    }
}
