<?php
declare(strict_types=1);

namespace Foundry\Explain\Collectors;

use Foundry\Explain\ExplainArtifactCatalog;
use Foundry\Explain\ExplainContext;
use Foundry\Explain\ExplainOptions;
use Foundry\Explain\ExplainSubject;

final readonly class DocsContextCollector implements ExplainContextCollectorInterface
{
    public function __construct(private ExplainArtifactCatalog $artifacts)
    {
    }

    public function supports(ExplainSubject $subject): bool
    {
        return true;
    }

    public function collect(ExplainSubject $subject, ExplainContext $context, ExplainOptions $options): void
    {
        $rows = [];
        foreach ($this->artifacts->docsPages() as $row) {
            if (!is_array($row) || !$this->matchesSubject($row, $subject)) {
                continue;
            }

            $rows[] = [
                'id' => (string) ($row['id'] ?? ''),
                'title' => (string) ($row['title'] ?? ''),
                'path' => (string) ($row['path'] ?? ''),
                'source' => (string) ($row['source'] ?? 'docs'),
            ];
        }

        $context->setDocs(['items' => $rows]);
    }

    /**
     * @param array<string,mixed> $row
     */
    private function matchesSubject(array $row, ExplainSubject $subject): bool
    {
        $subjects = array_values(array_map('strval', (array) ($row['subjects'] ?? [])));
        if (in_array($subject->kind, $subjects, true)) {
            return true;
        }

        if ($subject->kind !== 'command') {
            return false;
        }

        $signature = strtolower(trim((string) ($subject->metadata['signature'] ?? $subject->label)));
        foreach ((array) ($row['commands'] ?? []) as $command) {
            if (strtolower(trim((string) $command)) === $signature) {
                return true;
            }
        }

        return false;
    }
}
