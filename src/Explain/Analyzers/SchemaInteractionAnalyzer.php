<?php
declare(strict_types=1);

namespace Foundry\Explain\Analyzers;

use Foundry\Explain\ExplainContext;
use Foundry\Explain\ExplainOptions;
use Foundry\Explain\ExplainSubject;
use Foundry\Explain\ExplainSupport;

final class SchemaInteractionAnalyzer implements SectionAnalyzerInterface
{
    public function supports(ExplainSubject $subject): bool
    {
        return in_array($subject->kind, ['feature', 'route', 'schema'], true);
    }

    public function sectionId(): string
    {
        return 'schema_interaction';
    }

    public function analyze(ExplainSubject $subject, ExplainContext $context, ExplainOptions $options): array
    {
        $schemas = $context->schemas();

        return [
            'items' => ExplainSupport::uniqueRows(array_values(array_filter((array) ($schemas['items'] ?? []), 'is_array'))),
            'reads' => ExplainSupport::uniqueRows(array_values(array_filter((array) ($schemas['reads'] ?? []), 'is_array'))),
            'writes' => ExplainSupport::uniqueRows(array_values(array_filter((array) ($schemas['writes'] ?? []), 'is_array'))),
            'fields' => array_values(array_filter((array) ($schemas['fields'] ?? []), 'is_array')),
            'subject' => is_array($schemas['subject'] ?? null) ? $schemas['subject'] : null,
        ];
    }
}
