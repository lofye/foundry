<?php
declare(strict_types=1);

namespace Foundry\Explain\Analyzers;

use Foundry\Explain\ExplainContext;
use Foundry\Explain\ExplainOptions;
use Foundry\Explain\ExplainSubject;
use Foundry\Explain\ExplainSupport;

final class RouteSubjectAnalyzer implements SubjectAnalyzerInterface
{
    public function supports(ExplainSubject $subject): bool
    {
        return $subject->kind === 'route';
    }

    public function analyze(ExplainSubject $subject, ExplainContext $context, ExplainOptions $options): array
    {
        $signature = ExplainSupport::normalizeRouteSignature((string) ($subject->metadata['signature'] ?? $subject->label));
        $feature = trim((string) ($subject->metadata['feature'] ?? ''));
        $pipeline = is_array($context->get('pipeline')) ? $context->get('pipeline') : [];
        $schemas = is_array($context->get('schemas')) ? $context->get('schemas') : [];
        $events = is_array($context->get('events')) ? $context->get('events') : [];
        $workflows = is_array($context->get('workflows')) ? $context->get('workflows') : [];

        $executionFlow = [];
        if ($options->includeExecutionFlow) {
            $jobs = $this->jobsForFeature($feature, $context);
            $emittedEvents = array_keys((array) ($events['emitted'] ?? []));
            $workflowItems = array_values(array_filter((array) ($workflows['items'] ?? []), 'is_array'));
            $executionFlow = [
                'pipeline' => $pipeline['execution_plan'] ?? null,
                'route' => $signature,
                'stages' => array_values(array_map('strval', (array) (($pipeline['execution_plan']['stages'] ?? []) ?: []))),
                'guards' => array_values(array_filter((array) ($pipeline['guards'] ?? []), 'is_array')),
                'events' => array_values(array_map(
                    static fn (string $name): array => ['name' => $name],
                    array_values(array_map('strval', $emittedEvents)),
                )),
                'jobs' => array_values(array_map(
                    static fn (string $name): array => ['name' => $name],
                    $jobs,
                )),
                'workflows' => $workflowItems,
                'steps' => ExplainSupport::orderedUniqueStrings(array_merge(
                    [$signature],
                    array_values(array_map(
                        static fn (array $guard): string => trim((string) ($guard['type'] ?? $guard['id'] ?? 'guard')) . ' guard',
                        array_values(array_filter((array) ($pipeline['guards'] ?? []), 'is_array')),
                    )),
                    array_values(array_map('strval', (array) ($pipeline['execution_plan']['stages'] ?? []))),
                    $feature !== '' ? [$feature] : [],
                    array_values(array_map('strval', $emittedEvents)),
                    array_values(array_map(
                        static fn (array $workflow): string => (string) ($workflow['resource'] ?? $workflow['label'] ?? 'workflow'),
                        $workflowItems,
                    )),
                    $jobs,
                )),
            ];
        }

        return [
            'sections' => [
                ExplainSupport::section('route', 'Route', [
                    'signature' => $signature,
                    'method' => $subject->metadata['method'] ?? null,
                    'path' => $subject->metadata['path'] ?? null,
                    'feature' => $feature !== '' ? $feature : null,
                    'schemas' => $schemas,
                ]),
            ],
            'execution_flow' => $executionFlow,
            'related_commands' => [
                $context->commandPrefix . ' inspect route ' . $signature . ' --json',
                $context->commandPrefix . ' inspect graph --command=' . $signature . ' --json',
                $context->commandPrefix . ' inspect execution-plan ' . $signature . ' --json',
            ],
        ];
    }

    /**
     * @return array<int,string>
     */
    private function jobsForFeature(string $feature, ExplainContext $context): array
    {
        if ($feature === '') {
            return [];
        }

        $node = $context->graph->node('feature:' . $feature);
        if ($node === null) {
            return [];
        }

        return array_values(array_map('strval', (array) ($node->payload()['jobs']['dispatch'] ?? [])));
    }
}
