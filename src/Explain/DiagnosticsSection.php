<?php
declare(strict_types=1);

namespace Foundry\Explain;

final class DiagnosticsSection extends ExplainArrayView
{
    /**
     * @param array<string,mixed> $data
     */
    public function __construct(array $data = [])
    {
        $summary = is_array($data['summary'] ?? null) ? $data['summary'] : [];

        $this->data = [
            'summary' => [
                'error' => (int) ($summary['error'] ?? 0),
                'warning' => (int) ($summary['warning'] ?? 0),
                'info' => (int) ($summary['info'] ?? 0),
                'total' => (int) ($summary['total'] ?? 0),
            ],
            'items' => array_values(array_filter((array) ($data['items'] ?? []), 'is_array')),
        ];
    }
}
