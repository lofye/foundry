<?php
declare(strict_types=1);

namespace Foundry\Explain\Analyzers;

use Foundry\Explain\ExplainContext;
use Foundry\Explain\ExplainOptions;
use Foundry\Explain\ExplainSubject;
use Foundry\Explain\ExplainSupport;

final class EventSubjectAnalyzer implements SubjectAnalyzerInterface
{
    public function supports(ExplainSubject $subject): bool
    {
        return $subject->kind === 'event';
    }

    public function analyze(ExplainSubject $subject, ExplainContext $context, ExplainOptions $options): array
    {
        $event = is_array($context->get('events')) ? $context->get('events') : [];
        $workflows = is_array($context->get('workflows')) ? $context->get('workflows') : [];
        $name = (string) ($subject->metadata['name'] ?? $subject->label);

        return [
            'sections' => [
                ExplainSupport::section('event', 'Event', [
                    'name' => $name,
                    'emitters' => $event['emitters'] ?? [],
                    'subscribers' => $event['subscribers'] ?? [],
                    'schema' => $event['event']['schema'] ?? null,
                    'workflows' => $workflows['items'] ?? [],
                ]),
            ],
            'related_commands' => [
                $context->commandPrefix . ' inspect graph --event=' . $name . ' --json',
                $context->commandPrefix . ' verify contracts --json',
            ],
        ];
    }
}
