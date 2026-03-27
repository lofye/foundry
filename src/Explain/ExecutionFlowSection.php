<?php
declare(strict_types=1);

namespace Foundry\Explain;

final class ExecutionFlowSection extends ExplainArrayView
{
    /**
     * @param array<string,mixed> $data
     */
    public function __construct(array $data = [])
    {
        $this->data = [
            'entries' => $this->rows($data['entries'] ?? []),
            'stages' => $this->rows($data['stages'] ?? []),
            'guards' => $this->rows($data['guards'] ?? []),
            'action' => is_array($data['action'] ?? null) ? $data['action'] : null,
            'events' => $this->rows($data['events'] ?? []),
            'workflows' => $this->rows($data['workflows'] ?? []),
            'jobs' => $this->rows($data['jobs'] ?? []),
        ];
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function entries(): array
    {
        return $this->data['entries'];
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
