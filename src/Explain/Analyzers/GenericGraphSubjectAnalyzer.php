<?php
declare(strict_types=1);

namespace Foundry\Explain\Analyzers;

use Foundry\Explain\ExplainContext;
use Foundry\Explain\ExplainOptions;
use Foundry\Explain\ExplainSubject;
use Foundry\Explain\ExplainSupport;

final class GenericGraphSubjectAnalyzer implements SubjectAnalyzerInterface
{
    public function supports(ExplainSubject $subject): bool
    {
        return $subject->graphNodeIds !== [];
    }

    public function analyze(ExplainSubject $subject, ExplainContext $context, ExplainOptions $options): array
    {
        $impact = is_array($context->get('impact')) ? $context->get('impact') : null;
        $items = [
            'id' => $subject->id,
            'kind' => $subject->kind,
            'graph_node_ids' => $subject->graphNodeIds,
            'aliases' => $subject->aliases,
            'source_path' => $subject->metadata['source_path'] ?? null,
        ];
        if (isset($subject->metadata['feature'])) {
            $items['feature'] = $subject->metadata['feature'];
        }

        $sections = [ExplainSupport::section('subject', 'Subject', $items)];
        if (is_array($impact)) {
            $sections[] = ExplainSupport::section('impact', 'Impact', [
                'risk' => $impact['risk'] ?? 'low',
                'affected_features' => $impact['affected_features'] ?? [],
                'affected_routes' => $impact['affected_routes'] ?? [],
                'affected_events' => $impact['affected_events'] ?? [],
                'affected_jobs' => $impact['affected_jobs'] ?? [],
                'affected_projections' => $impact['affected_projections'] ?? [],
            ]);
        }

        $commands = [$context->commandPrefix . ' inspect node ' . $subject->id . ' --json'];
        if (is_array($impact)) {
            $commands = array_merge($commands, array_values(array_map('strval', (array) ($impact['recommended_verification'] ?? []))));
        }

        return [
            'sections' => $sections,
            'related_commands' => $commands,
        ];
    }
}
