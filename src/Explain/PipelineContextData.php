<?php
declare(strict_types=1);

namespace Foundry\Explain;

final class PipelineContextData extends ExplainArrayView
{
    /**
     * @param array<string,mixed> $data
     */
    public function __construct(array $data = [])
    {
        $this->data = [
            'feature' => $this->nullableString($data['feature'] ?? null),
            'route_signature' => $this->nullableString($data['route_signature'] ?? null),
            'execution_plan' => is_array($data['execution_plan'] ?? null) ? $data['execution_plan'] : null,
            'stages' => $this->rows($data['stages'] ?? []),
            'guards' => $this->rows($data['guards'] ?? []),
            'interceptors' => $this->interceptors($data['interceptors'] ?? []),
            'action' => is_array($data['action'] ?? null) ? $data['action'] : null,
            'jobs' => $this->rows($data['jobs'] ?? []),
            'permissions' => $this->rows($data['permissions'] ?? []),
            'definition' => is_array($data['definition'] ?? null) ? $data['definition'] : [],
        ];
    }

    private function nullableString(mixed $value): ?string
    {
        $normalized = trim((string) $value);

        return $normalized !== '' ? $normalized : null;
    }

    /**
     * @param mixed $rows
     * @return array<int,array<string,mixed>>
     */
    private function rows(mixed $rows): array
    {
        return array_values(array_filter((array) $rows, 'is_array'));
    }

    /**
     * @param mixed $interceptors
     * @return array<string,array<int,array<string,mixed>>>
     */
    private function interceptors(mixed $interceptors): array
    {
        $normalized = [];
        foreach ((array) $interceptors as $stage => $rows) {
            $stageRows = $this->rows($rows);
            if ($stageRows === []) {
                continue;
            }

            $normalized[(string) $stage] = $stageRows;
        }

        ksort($normalized);

        return $normalized;
    }
}
