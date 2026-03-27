<?php
declare(strict_types=1);

namespace Foundry\Explain;

final class DiagnosticsContextData extends ExplainArrayView
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
            'items' => $this->rows($data['items'] ?? []),
        ];
    }

    /**
     * @param mixed $rows
     * @return array<int,array<string,mixed>>
     */
    private function rows(mixed $rows): array
    {
        $filtered = [];
        foreach ((array) $rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $filtered[] = $row;
        }

        usort(
            $filtered,
            static fn (array $left, array $right): int => strcmp((string) ($left['severity'] ?? ''), (string) ($right['severity'] ?? ''))
                ?: strcmp((string) ($left['code'] ?? ''), (string) ($right['code'] ?? ''))
                ?: strcmp((string) ($left['message'] ?? ''), (string) ($right['message'] ?? '')),
        );

        return array_values($filtered);
    }
}
