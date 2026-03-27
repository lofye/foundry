<?php

declare(strict_types=1);

namespace Foundry\Explain\Analyzers;

use Foundry\Explain\ExplainContext;
use Foundry\Explain\ExplainOptions;
use Foundry\Explain\ExplainSubject;
use Foundry\Explain\ExplainSupport;

final class DependencyAnalyzer implements SectionAnalyzerInterface
{
    public function supports(ExplainSubject $subject): bool
    {
        return true;
    }

    public function sectionId(): string
    {
        return 'dependencies';
    }

    public function analyze(ExplainSubject $subject, ExplainContext $context, ExplainOptions $options): array
    {
        $rows = [];
        $neighborhood = $context->graphNeighborhood();
        $schemas = $context->schemas();
        $pipeline = $context->pipeline();

        foreach ((array) ($neighborhood['dependencies'] ?? []) as $row) {
            if (is_array($row) && $this->includeDependency($subject, $row)) {
                $rows[] = $row;
            }
        }

        if ($subject->kind === 'route' && is_array($pipeline['action'] ?? null)) {
            $rows[] = $pipeline['action'];
        }

        foreach (array_values(array_filter((array) ($schemas['items'] ?? []), 'is_array')) as $schema) {
            $rows[] = $schema;
        }

        foreach (array_values(array_filter((array) ($pipeline['permissions'] ?? []), 'is_array')) as $permission) {
            $rows[] = [
                'id' => (string) ($permission['id'] ?? ''),
                'kind' => 'permission',
                'label' => (string) ($permission['name'] ?? ''),
            ];
        }

        return ['items' => ExplainSupport::uniqueRows($rows)];
    }

    /**
     * @param array<string,mixed> $row
     */
    private function includeDependency(ExplainSubject $subject, array $row): bool
    {
        $kind = (string) ($row['kind'] ?? '');

        return match ($subject->kind) {
            'feature' => in_array($kind, ['feature', 'schema', 'extension', 'permission', 'query', 'cache'], true),
            'route' => in_array($kind, ['feature', 'schema', 'permission'], true),
            'workflow' => in_array($kind, ['feature', 'event', 'schema', 'permission'], true),
            'event' => in_array($kind, ['schema'], true),
            'pipeline_stage' => in_array($kind, ['pipeline_stage', 'guard'], true),
            'schema' => in_array($kind, ['feature'], true),
            'extension' => in_array($kind, ['extension', 'feature'], true),
            'job' => in_array($kind, ['feature', 'event', 'schema'], true),
            default => in_array($kind, ['feature', 'schema'], true),
        };
    }
}
