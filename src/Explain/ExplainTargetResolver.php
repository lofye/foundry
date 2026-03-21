<?php
declare(strict_types=1);

namespace Foundry\Explain;

use Foundry\Compiler\ApplicationGraph;
use Foundry\Compiler\IR\GraphNode;
use Foundry\Support\FoundryError;

final class ExplainTargetResolver
{
    /**
     * @var array<int,ExplainSubject>|null
     */
    private ?array $candidateCache = null;

    public function __construct(
        private readonly ApplicationGraph $graph,
        private readonly ExplainArtifactCatalog $artifacts,
    ) {
    }

    public function resolve(ExplainTarget $target): ExplainSubject
    {
        $selector = trim($target->selector);
        if ($selector === '') {
            throw new FoundryError('EXPLAIN_TARGET_REQUIRED', 'validation', [], 'Explain target is required.');
        }

        if ($target->kind !== null && $target->kind !== '') {
            $resolved = $this->resolveExplicit($target->kind, $selector);
            if ($resolved !== null) {
                return $resolved;
            }

            $candidates = $this->fuzzyMatches($selector, $target->kind);
            if ($candidates !== []) {
                throw new FoundryError(
                    'EXPLAIN_TARGET_AMBIGUOUS',
                    'validation',
                    [
                        'target' => $target->raw,
                        'kind' => $target->kind,
                        'candidates' => array_map($this->candidateSummary(...), $candidates),
                    ],
                    $this->ambiguityMessage($target->raw, $candidates),
                );
            }

            throw new FoundryError(
                'EXPLAIN_TARGET_NOT_FOUND',
                'not_found',
                ['target' => $target->raw, 'kind' => $target->kind],
                'Explain target not found.',
            );
        }

        $exactNode = $this->graph->node($selector);
        if ($exactNode instanceof GraphNode) {
            return $this->subjectFromGraphNode($exactNode);
        }

        $aliasMatches = $this->exactAliasMatches($selector, includeRouteAndCommand: false);
        if (count($aliasMatches) === 1) {
            return $aliasMatches[0];
        }
        if (count($aliasMatches) > 1) {
            throw new FoundryError(
                'EXPLAIN_TARGET_AMBIGUOUS',
                'validation',
                ['target' => $target->raw, 'candidates' => array_map($this->candidateSummary(...), $aliasMatches)],
                $this->ambiguityMessage($target->raw, $aliasMatches),
            );
        }

        $routeOrCommandMatches = $this->exactAliasMatches($selector, includeRouteAndCommand: true, onlyRouteAndCommand: true);
        if (count($routeOrCommandMatches) === 1) {
            return $routeOrCommandMatches[0];
        }
        if (count($routeOrCommandMatches) > 1) {
            throw new FoundryError(
                'EXPLAIN_TARGET_AMBIGUOUS',
                'validation',
                ['target' => $target->raw, 'candidates' => array_map($this->candidateSummary(...), $routeOrCommandMatches)],
                $this->ambiguityMessage($target->raw, $routeOrCommandMatches),
            );
        }

        $fuzzy = $this->fuzzyMatches($selector);
        if (count($fuzzy) === 1) {
            return $fuzzy[0];
        }
        if (count($fuzzy) > 1) {
            throw new FoundryError(
                'EXPLAIN_TARGET_AMBIGUOUS',
                'validation',
                ['target' => $target->raw, 'candidates' => array_map($this->candidateSummary(...), $fuzzy)],
                $this->ambiguityMessage($target->raw, $fuzzy),
            );
        }

        throw new FoundryError(
            'EXPLAIN_TARGET_NOT_FOUND',
            'not_found',
            ['target' => $target->raw],
            'Explain target not found.',
        );
    }

    private function resolveExplicit(string $kind, string $selector): ?ExplainSubject
    {
        if ($kind === 'command') {
            return $this->resolveCommand($selector);
        }

        if ($kind === 'extension') {
            return $this->resolveExtension($selector);
        }

        foreach ($this->possibleGraphNodeIds($kind, $selector) as $nodeId) {
            $node = $this->graph->node($nodeId);
            if ($node instanceof GraphNode) {
                return $this->subjectFromGraphNode($node);
            }
        }

        $matches = $this->exactAliasMatches($selector, includeRouteAndCommand: true, onlyRouteAndCommand: $kind === 'route');
        $matches = array_values(array_filter(
            $matches,
            static fn (ExplainSubject $subject): bool => $subject->kind === $kind,
        ));

        if (count($matches) === 1) {
            return $matches[0];
        }
        if (count($matches) > 1) {
            throw new FoundryError(
                'EXPLAIN_TARGET_AMBIGUOUS',
                'validation',
                [
                    'target' => $kind . ':' . $selector,
                    'kind' => $kind,
                    'candidates' => array_map($this->candidateSummary(...), $matches),
                ],
                $this->ambiguityMessage($kind . ':' . $selector, $matches),
            );
        }

        return null;
    }

    /**
     * @return array<int,ExplainSubject>
     */
    private function exactAliasMatches(
        string $selector,
        bool $includeRouteAndCommand,
        bool $onlyRouteAndCommand = false,
    ): array {
        $normalized = strtolower(trim(ExplainSupport::normalizeRouteSignature($selector)));
        $matches = [];

        foreach ($this->candidates() as $candidate) {
            $isRouteOrCommand = in_array($candidate->kind, ['route', 'command'], true);
            if ($onlyRouteAndCommand && !$isRouteOrCommand) {
                continue;
            }
            if (!$includeRouteAndCommand && $isRouteOrCommand) {
                continue;
            }

            foreach ($candidate->aliases as $alias) {
                $candidateAlias = strtolower(trim(ExplainSupport::normalizeRouteSignature($alias)));
                if ($candidateAlias === $normalized) {
                    $matches[$candidate->id] = $candidate;
                    break;
                }
            }
        }

        return array_values($matches);
    }

    /**
     * @return array<int,ExplainSubject>
     */
    private function fuzzyMatches(string $selector, ?string $kind = null): array
    {
        $needle = strtolower(trim($selector));
        if ($needle === '') {
            return [];
        }

        $matches = [];
        foreach ($this->candidates() as $candidate) {
            if ($kind !== null && $candidate->kind !== $kind) {
                continue;
            }

            $haystacks = array_merge([$candidate->id, $candidate->label], $candidate->aliases);
            foreach ($haystacks as $haystack) {
                if (str_contains(strtolower($haystack), $needle)) {
                    $matches[$candidate->id] = $candidate;
                    break;
                }
            }
        }

        usort(
            $matches,
            static fn (ExplainSubject $left, ExplainSubject $right): int => strcmp($left->kind, $right->kind)
                ?: strcmp($left->label, $right->label)
                ?: strcmp($left->id, $right->id),
        );

        return array_values($matches);
    }

    private function resolveCommand(string $selector): ?ExplainSubject
    {
        $normalized = strtolower(trim($selector));
        foreach ($this->commandCandidates() as $candidate) {
            foreach ($candidate->aliases as $alias) {
                if (strtolower(trim($alias)) === $normalized) {
                    return $candidate;
                }
            }
        }

        return null;
    }

    private function resolveExtension(string $selector): ?ExplainSubject
    {
        $normalized = strtolower(trim($selector));
        foreach ($this->extensionCandidates() as $candidate) {
            foreach ($candidate->aliases as $alias) {
                if (strtolower(trim($alias)) === $normalized) {
                    return $candidate;
                }
            }
        }

        return null;
    }

    /**
     * @return array<int,string>
     */
    private function possibleGraphNodeIds(string $kind, string $selector): array
    {
        $selector = trim($selector);
        if ($selector === '') {
            return [];
        }

        if (str_starts_with($selector, $kind . ':')) {
            return [$selector];
        }

        return match ($kind) {
            'feature' => ['feature:' . $selector],
            'route' => [
                ExplainSupport::routeNodeId($selector),
                'route:' . ExplainSupport::normalizeRouteSignature($selector),
            ],
            'event' => ['event:' . $selector],
            'workflow' => ['workflow:' . $selector],
            'job' => ['job:' . $selector],
            'schema' => ['schema:' . $selector],
            'pipeline_stage' => ['pipeline_stage:' . $selector],
            'guard' => ['guard:' . $selector],
            'permission' => ['permission:' . $selector],
            default => [$kind . ':' . $selector],
        };
    }

    /**
     * @return array<int,ExplainSubject>
     */
    private function candidates(): array
    {
        if ($this->candidateCache !== null) {
            return $this->candidateCache;
        }

        $rows = [];
        foreach ($this->graph->nodes() as $node) {
            $subject = $this->subjectFromGraphNode($node);
            $rows[$subject->id] = $subject;
        }

        foreach ($this->extensionCandidates() as $candidate) {
            $rows[$candidate->id] = $candidate;
        }

        foreach ($this->commandCandidates() as $candidate) {
            $rows[$candidate->id] = $candidate;
        }

        usort(
            $rows,
            static fn (ExplainSubject $left, ExplainSubject $right): int => strcmp($left->kind, $right->kind)
                ?: strcmp($left->label, $right->label)
                ?: strcmp($left->id, $right->id),
        );

        return $this->candidateCache = array_values($rows);
    }

    /**
     * @return array<int,ExplainSubject>
     */
    private function extensionCandidates(): array
    {
        $rows = [];
        foreach ($this->artifacts->extensions() as $row) {
            $name = trim((string) ($row['name'] ?? ''));
            if ($name === '') {
                continue;
            }

            $rows[] = new ExplainSubject(
                kind: 'extension',
                id: 'extension:' . $name,
                label: $name,
                graphNodeIds: [],
                aliases: [$name],
                metadata: $row,
            );
        }

        return $rows;
    }

    /**
     * @return array<int,ExplainSubject>
     */
    private function commandCandidates(): array
    {
        $rows = [];
        foreach ($this->artifacts->cliCommands() as $row) {
            if (!is_array($row)) {
                continue;
            }

            $signature = trim((string) ($row['signature'] ?? ''));
            if ($signature === '') {
                continue;
            }

            $aliases = [$signature];
            if (!str_contains($signature, ' ')) {
                $aliases[] = $signature;
            }

            $rows[] = new ExplainSubject(
                kind: 'command',
                id: 'command:' . $signature,
                label: $signature,
                graphNodeIds: [],
                aliases: ExplainSupport::uniqueStrings($aliases),
                metadata: $row,
            );
        }

        return $rows;
    }

    private function subjectFromGraphNode(GraphNode $node): ExplainSubject
    {
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

        return new ExplainSubject(
            kind: ExplainSupport::subjectKindForNodeType($node->type()),
            id: $node->id(),
            label: ExplainSupport::nodeLabel($node),
            graphNodeIds: [$node->id()],
            aliases: ExplainSupport::nodeAliases($node),
            metadata: $metadata,
        );
    }

    /**
     * @return array<string,mixed>
     */
    private function candidateSummary(ExplainSubject $subject): array
    {
        return [
            'id' => $subject->id,
            'kind' => $subject->kind,
            'label' => $subject->label,
            'aliases' => array_slice($subject->aliases, 0, 5),
        ];
    }

    /**
     * @param array<int,ExplainSubject> $candidates
     */
    private function ambiguityMessage(string $target, array $candidates): string
    {
        $lines = [
            'Ambiguous target: "' . trim($target) . '"',
            '',
            'Did you mean:',
            '',
        ];

        foreach (array_slice($candidates, 0, 5) as $candidate) {
            $lines[] = '  ' . $candidate->label . ' (' . $candidate->kind . ')';
        }

        $lines[] = '';
        $lines[] = 'Use a more specific target, or prefix with type:';
        $lines[] = '';

        foreach (array_slice($candidates, 0, 3) as $candidate) {
            $lines[] = '  foundry explain ' . $this->typedSelectorExample($candidate);
        }

        return implode(PHP_EOL, $lines);
    }

    private function typedSelectorExample(ExplainSubject $candidate): string
    {
        $selector = match ($candidate->kind) {
            'feature' => trim((string) ($candidate->metadata['feature'] ?? $candidate->label)),
            'route' => ExplainSupport::normalizeRouteSignature((string) ($candidate->metadata['signature'] ?? $candidate->label)),
            'event' => trim((string) ($candidate->metadata['name'] ?? $candidate->label)),
            'workflow' => trim((string) ($candidate->metadata['resource'] ?? $candidate->label)),
            'job' => trim((string) ($candidate->metadata['name'] ?? $candidate->label)),
            'schema' => trim((string) ($candidate->metadata['path'] ?? $candidate->label)),
            'pipeline_stage' => trim((string) ($candidate->metadata['name'] ?? $candidate->label)),
            'command' => trim((string) ($candidate->metadata['signature'] ?? $candidate->label)),
            'extension' => trim((string) ($candidate->metadata['name'] ?? $candidate->label)),
            default => trim($candidate->label),
        };

        if ($selector === '') {
            $selector = trim($candidate->label);
        }

        return $candidate->kind . ':' . $selector;
    }
}
