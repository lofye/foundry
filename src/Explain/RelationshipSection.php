<?php
declare(strict_types=1);

namespace Foundry\Explain;

final class RelationshipSection extends ExplainArrayView
{
    /**
     * @param array<string,mixed> $data
     */
    public function __construct(array $data = [])
    {
        $this->data = [
            'items' => ExplainSupport::uniqueRows(array_values(array_filter((array) ($data['items'] ?? []), 'is_array'))),
        ];
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function items(): array
    {
        return $this->data['items'];
    }
}
