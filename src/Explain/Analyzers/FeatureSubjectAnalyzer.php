<?php
declare(strict_types=1);

namespace Foundry\Explain\Analyzers;

use Foundry\Explain\ExplainContext;
use Foundry\Explain\ExplainOptions;
use Foundry\Explain\ExplainSubject;
use Foundry\Explain\ExplainSupport;

final class FeatureSubjectAnalyzer implements SubjectAnalyzerInterface
{
    public function supports(ExplainSubject $subject): bool
    {
        return $subject->kind === 'feature';
    }

    public function analyze(ExplainSubject $subject, ExplainContext $context, ExplainOptions $options): array
    {
        $feature = (string) ($subject->metadata['feature'] ?? $subject->label);
        $contracts = [
            'description' => $subject->metadata['description'] ?? null,
            'route' => $subject->metadata['route'] ?? null,
            'input_schema' => $subject->metadata['input_schema'] ?? null,
            'output_schema' => $subject->metadata['output_schema'] ?? null,
            'permissions' => $subject->metadata['auth']['permissions'] ?? [],
            'events' => $subject->metadata['events']['emit'] ?? [],
            'jobs' => $subject->metadata['jobs']['dispatch'] ?? [],
        ];

        $pipeline = is_array($context->get('pipeline')) ? $context->get('pipeline') : [];
        $events = is_array($context->get('events')) ? $context->get('events') : [];
        $workflows = is_array($context->get('workflows')) ? $context->get('workflows') : [];

        $executionFlow = [];
        if ($options->includeExecutionFlow) {
            $routeSignature = trim((string) ($pipeline['route_signature'] ?? ''));
            $stages = array_values(array_map('strval', (array) (($pipeline['execution_plan']['stages'] ?? []) ?: [])));
            $guards = array_values(array_filter((array) ($pipeline['guards'] ?? []), 'is_array'));
            $jobs = array_values(array_map('strval', (array) ($subject->metadata['jobs']['dispatch'] ?? [])));
            $emittedEvents = array_keys((array) ($events['emitted'] ?? []));
            $workflowItems = array_values(array_filter((array) ($workflows['items'] ?? []), 'is_array'));

            $steps = [];
            if ($routeSignature !== '') {
                $steps[] = $routeSignature;
            }
            foreach ($guards as $guard) {
                $type = trim((string) ($guard['type'] ?? $guard['id'] ?? 'guard'));
                $steps[] = $type . ' guard';
            }
            $steps[] = $feature;
            foreach ($emittedEvents as $eventName) {
                $steps[] = (string) $eventName;
            }
            foreach ($workflowItems as $workflow) {
                $steps[] = (string) ($workflow['resource'] ?? $workflow['label'] ?? 'workflow');
            }
            foreach ($jobs as $jobName) {
                $steps[] = $jobName;
            }

            $executionFlow = [
                'pipeline' => $pipeline['execution_plan'] ?? null,
                'route' => $routeSignature !== '' ? $routeSignature : null,
                'stages' => $stages,
                'guards' => $guards,
                'events' => array_values(array_map(
                    static fn (string $name): array => ['name' => $name],
                    array_values(array_map('strval', $emittedEvents)),
                )),
                'jobs' => array_values(array_map(
                    static fn (string $name): array => ['name' => $name],
                    $jobs,
                )),
                'workflows' => $workflowItems,
                'steps' => ExplainSupport::orderedUniqueStrings($steps),
            ];
        }

        return [
            'sections' => [
                ExplainSupport::section('contracts', 'Contracts', $contracts),
            ],
            'execution_flow' => $executionFlow,
            'related_commands' => [
                $context->commandPrefix . ' inspect feature ' . $feature . ' --json',
                $context->commandPrefix . ' inspect graph --feature=' . $feature . ' --json',
                $context->commandPrefix . ' inspect execution-plan ' . $feature . ' --json',
                $context->commandPrefix . ' doctor --feature=' . $feature . ' --json',
                $context->commandPrefix . ' verify feature ' . $feature . ' --json',
            ],
        ];
    }
}
