<?php

declare(strict_types=1);

namespace Foundry\Explain\Collectors;

use Foundry\Compiler\ApplicationGraph;
use Foundry\Compiler\IR\GraphNode;
use Foundry\Explain\ExplainArtifactCatalog;
use Foundry\Explain\ExplainContext;
use Foundry\Explain\ExplainOrigin;
use Foundry\Explain\ExplainOptions;
use Foundry\Explain\ExplainSubject;
use Foundry\Explain\ExplainSupport;

final readonly class ExtensionContextCollector implements ExplainContextCollectorInterface
{
    public function __construct(
        private ExplainArtifactCatalog $artifacts,
        private ?ApplicationGraph $graph = null,
    ) {}

    public function supports(ExplainSubject $subject): bool
    {
        return true;
    }

    public function collect(ExplainSubject $subject, ExplainContext $context, ExplainOptions $options): void
    {
        $items = [];
        foreach ($this->artifacts->extensions() as $row) {
            if (!is_array($row)) {
                continue;
            }

            $row['graph_nodes'] = $this->graphNodesForExtensionRow($row);
            $items[] = $row;
        }

        $subjectRow = null;
        foreach ($items as $item) {
            if ($subject->kind === 'extension' && ('extension:' . (string) ($item['name'] ?? '')) === $subject->id) {
                $subjectRow = $item;
                break;
            }

            if ($subject->kind === 'pack' && ExplainOrigin::packNameFromRow($item) === $subject->label) {
                $subjectRow = $item;
                break;
            }
        }

        $context->setExtensions([
            'subject' => in_array($subject->kind, ['extension', 'pack'], true) ? ($subjectRow ?? $subject->metadata) : null,
            'items' => $items,
        ]);
    }

    /**
     * @param array<string,mixed> $row
     * @return array<int,array<string,mixed>>
     */
    private function graphNodesForExtensionRow(array $row): array
    {
        if (!$this->graph instanceof ApplicationGraph) {
            return [];
        }

        $rows = [];
        $extensionName = trim((string) ($row['name'] ?? ''));
        $packPath = $this->packInstallPath($row);
        foreach ($this->graph->nodes() as $node) {
            if (!$node instanceof GraphNode || !$this->matchesNode($node, $extensionName, $packPath)) {
                continue;
            }

            $rows[] = ExplainSupport::summarizeGraphNode($node);
        }

        return ExplainOrigin::sortAttributedRows(ExplainSupport::uniqueRows($rows));
    }

    /**
     * @param array<string,mixed> $row
     */
    private function packInstallPath(array $row): ?string
    {
        $sourcePath = trim((string) ($row['source_path'] ?? ''));
        if ($sourcePath === '') {
            return null;
        }

        return dirname(str_replace('\\', '/', $sourcePath));
    }

    private function matchesNode(GraphNode $node, string $extensionName, ?string $packPath): bool
    {
        $sourcePath = str_replace('\\', '/', $node->sourcePath());
        if ($packPath !== null && str_starts_with($sourcePath, rtrim($packPath, '/') . '/')) {
            return true;
        }

        $nodeExtension = trim((string) ($node->payload()['extension'] ?? ''));
        if ($extensionName !== '' && $nodeExtension !== '' && $nodeExtension === $extensionName) {
            return true;
        }

        return false;
    }
}
