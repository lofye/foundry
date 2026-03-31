<?php

declare(strict_types=1);

namespace Foundry\Explain;

final readonly class ExplainModel
{
    /**
     * @param array<string,mixed> $subject
     * @param array<string,mixed> $graph
     * @param array<string,mixed> $execution
     * @param array<string,mixed> $guards
     * @param array<string,mixed> $events
     * @param array<string,mixed> $schemas
     * @param array<string,mixed> $relationships
     * @param array<string,mixed> $diagnostics
     * @param array<string,mixed> $docs
     * @param array<string,mixed> $impact
     * @param array<string,mixed> $commands
     * @param array<string,mixed> $metadata
     * @param array<int,array<string,mixed>> $extensions
     */
    public function __construct(
        public array $subject,
        public array $graph,
        public array $execution,
        public array $guards,
        public array $events,
        public array $schemas,
        public array $relationships,
        public array $diagnostics,
        public array $docs,
        public array $impact,
        public array $commands,
        public array $metadata,
        public array $extensions,
    ) {}

    /**
     * @param array<string,mixed> $subject
     * @param array<string,mixed> $executionFlow
     * @param array<string,mixed> $relationships
     * @param array<string,mixed> $diagnostics
     * @param array<string,mixed> $schemaInteraction
     * @param array<int,string> $relatedCommands
     * @param array<int,array<string,mixed>> $relatedDocs
     * @param array<string,mixed> $metadata
     */
    public static function fromExplainData(
        ExplainContext $context,
        array $subject,
        array $executionFlow,
        array $relationships,
        array $diagnostics,
        array $schemaInteraction,
        array $relatedCommands,
        array $relatedDocs,
        array $metadata,
    ): self {
        $subjectRow = ExplainOrigin::applyToRow($subject);
        $subjectSource = is_array($subjectRow['source'] ?? null) ? $subjectRow['source'] : ['type' => 'core'];
        $extensionRows = self::extensionEntries($context);

        return new self(
            subject: $subjectRow,
            graph: [
                'node_ids' => array_values(array_map('strval', (array) ($subject['graph_node_ids'] ?? []))),
                'subject_node' => self::nullableAttributedRow($context->subjectNode(), $subjectSource),
                'neighbors' => [
                    'inbound' => self::normalizeRows((array) ($relationships['graph']['inbound'] ?? [])),
                    'outbound' => self::normalizeRows((array) ($relationships['graph']['outbound'] ?? [])),
                    'lateral' => self::normalizeRows((array) ($relationships['graph']['lateral'] ?? [])),
                ],
            ],
            execution: [
                'entries' => self::normalizeRows((array) ($executionFlow['entries'] ?? []), $subjectSource),
                'stages' => self::normalizeRows((array) ($executionFlow['stages'] ?? []), $subjectSource),
                'action' => self::nullableAttributedRow(
                    is_array($executionFlow['action'] ?? null) ? $executionFlow['action'] : [],
                    $subjectSource,
                ),
                'workflows' => self::normalizeRows((array) ($executionFlow['workflows'] ?? []), $subjectSource),
                'jobs' => self::normalizeRows((array) ($executionFlow['jobs'] ?? []), $subjectSource),
            ],
            guards: [
                'items' => self::normalizeRows((array) ($executionFlow['guards'] ?? []), $subjectSource),
            ],
            events: [
                'emits' => self::normalizeRows((array) ($context->events()['emitted'] ?? []), $subjectSource, keyedEventMap: true),
                'subscriptions' => self::eventSubscriptions((array) ($context->events()['subscribed'] ?? []), $subjectSource),
                'emitters' => self::featureRows((array) ($context->events()['emitters'] ?? []), $subjectSource),
                'subscribers' => self::featureRows((array) ($context->events()['subscribers'] ?? []), $subjectSource),
            ],
            schemas: [
                'subject' => self::nullableAttributedRow(
                    is_array($schemaInteraction['subject'] ?? null) ? $schemaInteraction['subject'] : [],
                    $subjectSource,
                ),
                'items' => self::normalizeRows((array) ($schemaInteraction['items'] ?? []), $subjectSource),
                'reads' => self::normalizeRows((array) ($schemaInteraction['reads'] ?? []), $subjectSource),
                'writes' => self::normalizeRows((array) ($schemaInteraction['writes'] ?? []), $subjectSource),
                'fields' => array_values(array_filter((array) ($schemaInteraction['fields'] ?? []), 'is_array')),
            ],
            relationships: [
                'dependsOn' => ['items' => self::normalizeRows((array) ($relationships['dependsOn']['items'] ?? []), $subjectSource)],
                'usedBy' => ['items' => self::normalizeRows((array) ($relationships['usedBy']['items'] ?? []), $subjectSource)],
                'graph' => [
                    'inbound' => self::normalizeRows((array) ($relationships['graph']['inbound'] ?? []), $subjectSource),
                    'outbound' => self::normalizeRows((array) ($relationships['graph']['outbound'] ?? []), $subjectSource),
                    'lateral' => self::normalizeRows((array) ($relationships['graph']['lateral'] ?? []), $subjectSource),
                ],
            ],
            diagnostics: [
                'summary' => is_array($diagnostics['summary'] ?? null) ? $diagnostics['summary'] : [],
                'items' => self::normalizeRows((array) ($diagnostics['items'] ?? []), $subjectSource),
            ],
            docs: [
                'related' => self::normalizeRows($relatedDocs),
            ],
            impact: is_array($context->impact()) ? $context->impact() : [],
            commands: [
                'subject' => self::nullableAttributedRow(
                    is_array($context->commands()['subject'] ?? null) ? $context->commands()['subject'] : [],
                    $subjectSource,
                ),
                'related' => self::commandRows($relatedCommands, $subjectSource),
            ],
            metadata: $metadata,
            extensions: $extensionRows,
        );
    }

    /**
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        return [
            'subject' => $this->subject,
            'graph' => $this->graph,
            'execution' => $this->execution,
            'guards' => $this->guards,
            'events' => $this->events,
            'schemas' => $this->schemas,
            'relationships' => $this->relationships,
            'diagnostics' => $this->diagnostics,
            'docs' => $this->docs,
            'impact' => $this->impact,
            'commands' => $this->commands,
            'metadata' => $this->metadata,
            'extensions' => $this->extensions,
        ];
    }

    /**
     * @param array<int,mixed> $rows
     * @param array<string,mixed>|null $fallbackSource
     * @return array<int,array<string,mixed>>
     */
    private static function normalizeRows(array $rows, ?array $fallbackSource = null, bool $keyedEventMap = false): array
    {
        $normalized = [];

        if ($keyedEventMap) {
            foreach ($rows as $name => $row) {
                if (!is_array($row)) {
                    continue;
                }

                $row['id'] = (string) ($row['id'] ?? ('event:' . (string) $name));
                $row['kind'] = (string) ($row['kind'] ?? 'event');
                $row['label'] = (string) ($row['label'] ?? (string) $name);
                $row['name'] = (string) ($row['name'] ?? (string) $name);
                $normalized[] = ExplainOrigin::applyToRow($row, $fallbackSource);
            }

            return ExplainOrigin::sortAttributedRows($normalized);
        }

        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $normalized[] = ExplainOrigin::applyToRow($row, $fallbackSource);
        }

        return ExplainOrigin::sortAttributedRows($normalized);
    }

    /**
     * @param array<string,mixed> $row
     * @param array<string,mixed>|null $fallbackSource
     * @return array<string,mixed>|null
     */
    private static function nullableAttributedRow(array $row, ?array $fallbackSource = null): ?array
    {
        if ($row === []) {
            return null;
        }

        return ExplainOrigin::applyToRow($row, $fallbackSource);
    }

    /**
     * @param array<int,string> $commands
     * @param array<string,mixed>|null $fallbackSource
     * @return array<int,array<string,mixed>>
     */
    private static function commandRows(array $commands, ?array $fallbackSource = null): array
    {
        $rows = [];
        foreach ($commands as $command) {
            $signature = trim((string) $command);
            if ($signature === '') {
                continue;
            }

            $rows[] = ExplainOrigin::applyToRow([
                'id' => 'command:' . $signature,
                'kind' => 'command',
                'label' => $signature,
                'signature' => $signature,
            ], $fallbackSource);
        }

        return ExplainOrigin::sortAttributedRows($rows);
    }

    /**
     * @param array<int|string,mixed> $events
     * @param array<string,mixed>|null $fallbackSource
     * @return array<int,array<string,mixed>>
     */
    private static function eventSubscriptions(array $events, ?array $fallbackSource = null): array
    {
        $rows = [];
        foreach ($events as $name => $features) {
            $eventName = trim((string) $name);
            if ($eventName === '') {
                continue;
            }

            $rows[] = ExplainOrigin::applyToRow([
                'id' => 'event:' . $eventName,
                'kind' => 'event',
                'label' => $eventName,
                'name' => $eventName,
                'subscribers' => array_values(array_filter(array_map('strval', (array) $features))),
            ], $fallbackSource);
        }

        return ExplainOrigin::sortAttributedRows($rows);
    }

    /**
     * @param array<int,mixed> $features
     * @param array<string,mixed>|null $fallbackSource
     * @return array<int,array<string,mixed>>
     */
    private static function featureRows(array $features, ?array $fallbackSource = null): array
    {
        $rows = [];
        foreach ($features as $feature) {
            $name = trim((string) $feature);
            if ($name === '') {
                continue;
            }

            $rows[] = ExplainOrigin::applyToRow([
                'id' => 'feature:' . $name,
                'kind' => 'feature',
                'label' => $name,
                'feature' => $name,
            ], $fallbackSource);
        }

        return ExplainOrigin::sortAttributedRows($rows);
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private static function extensionEntries(ExplainContext $context): array
    {
        $rows = [];
        foreach ((array) ($context->extensions()['items'] ?? []) as $row) {
            if (!is_array($row)) {
                continue;
            }

            $normalized = self::normalizeExtensionEntry($row);
            if ($normalized !== null) {
                $rows[] = $normalized;
            }
        }

        usort($rows, static fn(array $left, array $right): int => strcmp((string) ($left['type'] ?? ''), (string) ($right['type'] ?? ''))
            ?: strcmp((string) ($left['name'] ?? ''), (string) ($right['name'] ?? ''))
            ?: version_compare((string) ($left['version'] ?? '0.0.0'), (string) ($right['version'] ?? '0.0.0')));

        return array_values($rows);
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>|null
     */
    private static function normalizeExtensionEntry(array $row): ?array
    {
        $packName = ExplainOrigin::packNameFromRow($row);
        $type = $packName !== null ? 'pack' : 'extension';
        $name = $type === 'pack'
            ? $packName
            : trim((string) ($row['name'] ?? ''));

        if ($name === '') {
            return null;
        }

        $declaredContributions = is_array($row['declared_contributions'] ?? null) ? $row['declared_contributions'] : [];
        $provides = [];
        foreach ($declaredContributions as $key => $values) {
            if (is_string($key) && is_array($values) && $values !== []) {
                $provides[] = $key;
            }
        }
        if ($provides === []) {
            $provides = self::flattenProvides($row['provides'] ?? $row['capabilities'] ?? []);
        }
        sort($provides);

        $graphNodes = self::normalizeRows(array_values(array_filter((array) ($row['graph_nodes'] ?? []), 'is_array')));
        $affects = [];
        foreach ($graphNodes as $node) {
            $feature = trim((string) ($node['feature'] ?? ''));
            if ($feature !== '') {
                $affects[] = 'feature.' . $feature;
            }
        }
        $affects = ExplainSupport::uniqueStrings($affects);

        $entryPoints = ExplainSupport::uniqueStrings(array_values(array_filter(array_map(
            'strval',
            [
                is_array($row['pack_manifest'] ?? null) ? ($row['pack_manifest']['entry'] ?? null) : null,
                $row['class'] ?? null,
            ],
        ))));

        $diagnostics = array_values(array_filter((array) ($row['diagnostics'] ?? []), 'is_array'));
        $hasErrors = false;
        foreach ($diagnostics as $diagnostic) {
            if (strtolower((string) ($diagnostic['severity'] ?? '')) === 'error') {
                $hasErrors = true;
                break;
            }
        }
        $verified = !$hasErrors && (bool) ($row['enabled'] ?? false);

        return [
            'name' => $name,
            'version' => (string) ($row['version'] ?? '0.0.0'),
            'type' => $type,
            'provides' => $provides,
            'affects' => $affects,
            'entry_points' => $entryPoints,
            'nodes' => array_values(array_map(
                static fn(array $node): string => (string) ($node['id'] ?? $node['label'] ?? ''),
                $graphNodes,
            )),
            'verified' => $verified,
            'source' => $type === 'pack' ? ExplainOrigin::installSource($row) : 'local',
        ];
    }

    /**
     * @return array<int,string>
     */
    private static function flattenProvides(mixed $provides): array
    {
        $flattened = [];
        foreach ((array) $provides as $value) {
            if (is_array($value)) {
                foreach ($value as $nested) {
                    $candidate = trim((string) $nested);
                    if ($candidate !== '') {
                        $flattened[] = $candidate;
                    }
                }

                continue;
            }

            $candidate = trim((string) $value);
            if ($candidate !== '') {
                $flattened[] = $candidate;
            }
        }

        return ExplainSupport::uniqueStrings($flattened);
    }
}
