<?php

declare(strict_types=1);

namespace Foundry\Explain;

final readonly class ExplainSubject
{
    /**
     * @param array<int,string> $graphNodeIds
     * @param array<int,string> $aliases
     * @param array<string,mixed> $metadata
     */
    public function __construct(
        public string $kind,
        public string $id,
        public string $label,
        public array $graphNodeIds,
        public array $aliases,
        public array $metadata = [],
        public string $origin = 'core',
        public ?string $extension = null,
    ) {}

    /**
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'kind' => $this->kind,
            'label' => $this->label,
            'origin' => $this->origin,
            'extension' => $this->extension,
            'graph_node_ids' => $this->graphNodeIds,
            'aliases' => $this->aliases,
            'metadata' => $this->metadata,
        ];
    }
}
