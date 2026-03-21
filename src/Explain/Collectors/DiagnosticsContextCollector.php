<?php
declare(strict_types=1);

namespace Foundry\Explain\Collectors;

use Foundry\Explain\ExplainContext;
use Foundry\Explain\ExplainOptions;
use Foundry\Explain\ExplainSubject;

final class DiagnosticsContextCollector implements ExplainContextCollectorInterface
{
    public function supports(ExplainSubject $subject): bool
    {
        return true;
    }

    public function collect(ExplainSubject $subject, ExplainContext $context, ExplainOptions $options): void
    {
        $diagnostics = array_values(array_filter(
            (array) ($context->artifacts->diagnosticsReport()['diagnostics'] ?? []),
            fn (mixed $row): bool => $this->matchesSubject($row, $subject, $context),
        ));

        $summary = ['error' => 0, 'warning' => 0, 'info' => 0, 'total' => count($diagnostics)];
        foreach ($diagnostics as $row) {
            $severity = (string) ($row['severity'] ?? 'info');
            if (isset($summary[$severity])) {
                $summary[$severity]++;
            }
        }

        $context->set('diagnostics', [
            'summary' => $summary,
            'items' => $diagnostics,
        ]);
    }

    private function matchesSubject(mixed $row, ExplainSubject $subject, ExplainContext $context): bool
    {
        if (!is_array($row)) {
            return false;
        }

        $nodeId = trim((string) ($row['node_id'] ?? ''));
        $related = array_values(array_map('strval', (array) ($row['related_nodes'] ?? [])));
        $sourcePath = trim((string) ($row['source_path'] ?? ''));

        foreach ($subject->graphNodeIds as $graphNodeId) {
            if ($nodeId === $graphNodeId || in_array($graphNodeId, $related, true)) {
                return true;
            }
        }

        $subjectSourcePath = trim((string) ($subject->metadata['source_path'] ?? ''));
        if ($subjectSourcePath !== '' && $sourcePath === $subjectSourcePath) {
            return true;
        }

        $feature = trim((string) ($subject->metadata['feature'] ?? ''));
        if ($feature === '') {
            return false;
        }

        if ($sourcePath !== '' && str_starts_with($sourcePath, 'app/features/' . $feature . '/')) {
            return true;
        }

        if ($nodeId !== '') {
            $node = $context->graph->node($nodeId);
            if ($node !== null && trim((string) ($node->payload()['feature'] ?? '')) === $feature) {
                return true;
            }
        }

        return false;
    }
}
