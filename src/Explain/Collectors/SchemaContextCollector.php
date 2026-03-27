<?php

declare(strict_types=1);

namespace Foundry\Explain\Collectors;

use Foundry\Explain\ExplainArtifactCatalog;
use Foundry\Explain\ExplainContext;
use Foundry\Explain\ExplainOptions;
use Foundry\Explain\ExplainSubject;

final readonly class SchemaContextCollector implements ExplainContextCollectorInterface
{
    public function __construct(private ExplainArtifactCatalog $artifacts) {}

    public function supports(ExplainSubject $subject): bool
    {
        return in_array($subject->kind, ['feature', 'route', 'schema'], true);
    }

    public function collect(ExplainSubject $subject, ExplainContext $context, ExplainOptions $options): void
    {
        $schemas = $this->artifacts->schemaIndex();

        if ($subject->kind === 'feature') {
            $feature = trim((string) ($subject->metadata['feature'] ?? $subject->label));
            $context->setSchemas($this->featureSchemas($feature, $schemas));

            return;
        }

        if ($subject->kind === 'route') {
            $feature = trim((string) ($subject->metadata['feature'] ?? ''));
            $context->setSchemas($this->featureSchemas($feature, $schemas));

            return;
        }

        $document = is_array($subject->metadata['document'] ?? null) ? $subject->metadata['document'] : [];
        $fields = [];
        foreach ((array) ($document['properties'] ?? []) as $name => $definition) {
            $fields[] = [
                'name' => (string) $name,
                'type' => is_array($definition) ? (string) ($definition['type'] ?? 'mixed') : 'mixed',
            ];
        }

        $context->setSchemas([
            'subject' => [
                'id' => $subject->id,
                'kind' => 'schema',
                'label' => $subject->label,
                'path' => (string) ($subject->metadata['path'] ?? $subject->label),
                'role' => (string) ($subject->metadata['role'] ?? 'schema'),
                'feature' => (string) ($subject->metadata['feature'] ?? ''),
            ],
            'items' => [[
                'id' => $subject->id,
                'kind' => 'schema',
                'label' => $subject->label,
                'path' => (string) ($subject->metadata['path'] ?? $subject->label),
                'role' => (string) ($subject->metadata['role'] ?? 'schema'),
            ]],
            'reads' => [],
            'writes' => [],
            'fields' => $fields,
        ]);
    }

    /**
     * @param array<string,mixed> $schemas
     * @return array<string,mixed>
     */
    private function featureSchemas(string $feature, array $schemas): array
    {
        $rows = [];
        $reads = [];
        $writes = [];
        foreach ((array) ($schemas[$feature] ?? []) as $role => $path) {
            $row = [
                'id' => 'schema:' . (string) $path,
                'kind' => 'schema',
                'label' => (string) $path,
                'path' => (string) $path,
                'role' => (string) $role,
                'feature' => $feature !== '' ? $feature : null,
            ];
            $rows[] = $row;
            if ((string) $role === 'input') {
                $reads[] = $row;
            }
            if ((string) $role === 'output') {
                $writes[] = $row;
            }
        }

        return [
            'subject' => null,
            'items' => $rows,
            'reads' => $reads,
            'writes' => $writes,
            'fields' => [],
        ];
    }
}
