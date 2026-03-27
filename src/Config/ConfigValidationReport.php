<?php
declare(strict_types=1);

namespace Foundry\Config;

final readonly class ConfigValidationReport
{
    /**
     * @param array<int,ConfigValidationIssue> $items
     * @param array<string,array<string,mixed>> $schemas
     * @param array<int,string> $validatedSources
     */
    public function __construct(
        public array $items,
        public array $schemas,
        public array $validatedSources = [],
    ) {
    }

    public function hasErrors(): bool
    {
        foreach ($this->items as $item) {
            if ($item->severity === 'error') {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array{error:int,warning:int,info:int,total:int}
     */
    public function summary(): array
    {
        $summary = [
            'error' => 0,
            'warning' => 0,
            'info' => 0,
            'total' => 0,
        ];

        foreach ($this->items as $item) {
            $summary[$item->severity] = ($summary[$item->severity] ?? 0) + 1;
            $summary['total']++;
        }

        return $summary;
    }

    /**
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        $schemaIds = array_keys($this->schemas);
        sort($schemaIds);

        $validatedSources = array_values(array_unique(array_map('strval', $this->validatedSources)));
        sort($validatedSources);

        return [
            'summary' => $this->summary(),
            'items' => array_values(array_map(
                static fn (ConfigValidationIssue $item): array => $item->toArray(),
                $this->items,
            )),
            'schema_ids' => $schemaIds,
            'validated_sources' => $validatedSources,
        ];
    }
}
