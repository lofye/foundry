<?php
declare(strict_types=1);

namespace Foundry\Explain\Collectors;

use Foundry\Explain\ExplainContext;
use Foundry\Explain\ExplainOptions;
use Foundry\Explain\ExplainSubject;

final class SchemaContextCollector implements ExplainContextCollectorInterface
{
    public function supports(ExplainSubject $subject): bool
    {
        return in_array($subject->kind, ['feature', 'route', 'schema'], true);
    }

    public function collect(ExplainSubject $subject, ExplainContext $context, ExplainOptions $options): void
    {
        $schemas = $context->artifacts->schemaIndex();

        if ($subject->kind === 'feature') {
            $feature = trim((string) ($subject->metadata['feature'] ?? $subject->label));
            $context->set('schemas', is_array($schemas[$feature] ?? null) ? $schemas[$feature] : []);

            return;
        }

        if ($subject->kind === 'route') {
            $feature = trim((string) ($subject->metadata['feature'] ?? ''));
            $context->set('schemas', $feature !== '' && is_array($schemas[$feature] ?? null) ? $schemas[$feature] : []);

            return;
        }

        $context->set('schemas', [
            'path' => (string) ($subject->metadata['path'] ?? $subject->label),
            'role' => (string) ($subject->metadata['role'] ?? 'schema'),
            'feature' => (string) ($subject->metadata['feature'] ?? ''),
            'document' => $subject->metadata['document'] ?? null,
        ]);
    }
}
