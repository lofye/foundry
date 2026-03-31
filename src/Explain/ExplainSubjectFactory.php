<?php

declare(strict_types=1);

namespace Foundry\Explain;

use Foundry\Compiler\IR\GraphNode;

final class ExplainSubjectFactory
{
    public function fromGraphNode(GraphNode $node): ?ExplainSubject
    {
        $kind = ExplainSupport::canonicalSubjectKindForNodeType($node->type());
        if ($kind === null) {
            return null;
        }

        $metadata = $node->payload();
        $metadata['source_path'] = $node->sourcePath();
        $metadata['source_region'] = $node->sourceRegion();
        $metadata['graph_compatibility'] = $node->graphCompatibility();
        $metadata['primary_node'] = $node->toArray();

        $feature = ExplainSupport::featureFromNode($node);
        if ($feature !== null) {
            $metadata['feature'] = $feature;
        }

        if ($node->type() === 'route') {
            $metadata['signature'] = ExplainSupport::normalizeRouteSignature((string) ($metadata['signature'] ?? ''));
        }

        $origin = ExplainOrigin::subject($metadata, ExplainSupport::nodeLabel($node));

        return new ExplainSubject(
            kind: $kind,
            id: $node->id(),
            label: ExplainSupport::nodeLabel($node),
            graphNodeIds: [$node->id()],
            aliases: ExplainSupport::nodeAliases($node),
            origin: $origin['origin'],
            extension: $origin['extension'],
            metadata: $metadata,
        );
    }

    /**
     * @param array<string,mixed> $row
     */
    public function fromExtensionRow(array $row): ?ExplainSubject
    {
        $name = trim((string) ($row['name'] ?? ''));
        if ($name === '') {
            return null;
        }

        $aliases = [$name];
        $packName = ExplainOrigin::packNameFromRow($row);
        if ($packName !== null) {
            $aliases[] = $packName;
        }

        return new ExplainSubject(
            kind: 'extension',
            id: 'extension:' . $name,
            label: $name,
            graphNodeIds: [],
            aliases: ExplainSupport::uniqueStrings($aliases),
            origin: 'extension',
            extension: $packName ?? $name,
            metadata: $row,
        );
    }

    /**
     * @param array<string,mixed> $row
     */
    public function fromPackRow(array $row): ?ExplainSubject
    {
        $name = ExplainOrigin::packNameFromRow($row);
        if ($name === null) {
            return null;
        }

        return new ExplainSubject(
            kind: 'pack',
            id: 'pack:' . $name,
            label: $name,
            graphNodeIds: [],
            aliases: ExplainSupport::uniqueStrings([$name]),
            origin: 'extension',
            extension: $name,
            metadata: $row,
        );
    }

    /**
     * @param array<string,mixed> $row
     */
    public function fromCommandRow(array $row): ?ExplainSubject
    {
        $signature = trim((string) ($row['signature'] ?? ''));
        if ($signature === '') {
            return null;
        }

        $origin = ExplainOrigin::subject($row, $signature);

        $aliases = [$signature];
        if (!str_contains($signature, ' ')) {
            $aliases[] = $signature;
        }

        return new ExplainSubject(
            kind: 'command',
            id: 'command:' . $signature,
            label: $signature,
            graphNodeIds: [],
            aliases: ExplainSupport::uniqueStrings($aliases),
            origin: $origin['origin'],
            extension: $origin['extension'],
            metadata: $row,
        );
    }
}
