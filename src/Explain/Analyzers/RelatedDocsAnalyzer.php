<?php

declare(strict_types=1);

namespace Foundry\Explain\Analyzers;

use Foundry\Explain\ExplainContext;
use Foundry\Explain\ExplainOptions;
use Foundry\Explain\ExplainSubject;
use Foundry\Explain\ExplainSupport;

final class RelatedDocsAnalyzer implements SectionAnalyzerInterface
{
    public function supports(ExplainSubject $subject): bool
    {
        return true;
    }

    public function sectionId(): string
    {
        return 'related_docs';
    }

    public function analyze(ExplainSubject $subject, ExplainContext $context, ExplainOptions $options): array
    {
        if (!$options->includeRelatedDocs) {
            return ['items' => []];
        }

        return ['items' => ExplainSupport::uniqueRows(array_values(array_filter((array) ($context->docs()['items'] ?? []), 'is_array')))];
    }
}
