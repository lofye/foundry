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

        $executionFlow = [];
        if ($options->includeExecutionFlow) {
            $executionFlow = [
                'pipeline' => $pipeline['execution_plan'] ?? null,
                'route' => $signature,
                'stages' => array_values(array_map('strval', (array) (($pipeline['execution_plan']['stages'] ?? []) ?: []))),
                'guards' => array_values(array_filter((array) ($pipeline['guards'] ?? []), 'is_array')),
                'steps' => ExplainSupport::uniqueStrings(array_merge(
                    [$signature],
                    array_values(array_map('strval', (array) ($pipeline['execution_plan']['stages'] ?? []))),
                    $feature !== '' ? [$feature] : [],
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
}
