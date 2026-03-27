<?php
declare(strict_types=1);

namespace Foundry\Explain;

final class GraphNeighborhoodContext extends ExplainArrayView
{
    /**
     * @param array<string,mixed> $data
     */
    public function __construct(array $data = [])
    {
        $this->data = [
            'dependencies' => ExplainSupport::uniqueRows($this->rows($data['dependencies'] ?? [])),
            'dependents' => ExplainSupport::uniqueRows($this->rows($data['dependents'] ?? [])),
            'inbound' => ExplainSupport::uniqueRows($this->rows($data['inbound'] ?? [])),
            'outbound' => ExplainSupport::uniqueRows($this->rows($data['outbound'] ?? [])),
            'neighbors' => ExplainSupport::uniqueRows($this->rows($data['neighbors'] ?? [])),
        ];
    }

    /**
     * @param mixed $rows
     * @return array<int,array<string,mixed>>
     */
    private function rows(mixed $rows): array
    {
        return array_values(array_filter((array) $rows, 'is_array'));
    }
}
