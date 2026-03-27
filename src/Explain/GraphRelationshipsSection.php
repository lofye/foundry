<?php
declare(strict_types=1);

namespace Foundry\Explain;

final class GraphRelationshipsSection extends ExplainArrayView
{
    /**
     * @param array<string,mixed> $data
     */
    public function __construct(array $data = [])
    {
        $this->data = [
            'inbound' => ExplainSupport::uniqueRows(array_values(array_filter((array) ($data['inbound'] ?? []), 'is_array'))),
            'outbound' => ExplainSupport::uniqueRows(array_values(array_filter((array) ($data['outbound'] ?? []), 'is_array'))),
            'lateral' => ExplainSupport::uniqueRows(array_values(array_filter((array) ($data['lateral'] ?? []), 'is_array'))),
        ];
    }
}
