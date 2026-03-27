<?php
declare(strict_types=1);

namespace Foundry\Explain;

final class DocsContextData extends ExplainArrayView
{
    /**
     * @param array<string,mixed> $data
     */
    public function __construct(array $data = [])
    {
        $items = array_values(array_filter((array) ($data['items'] ?? []), 'is_array'));
        usort(
            $items,
            static fn (array $left, array $right): int => strcmp((string) ($left['title'] ?? ''), (string) ($right['title'] ?? ''))
                ?: strcmp((string) ($left['path'] ?? ''), (string) ($right['path'] ?? ''))
                ?: strcmp((string) ($left['id'] ?? ''), (string) ($right['id'] ?? '')),
        );

        $this->data = [
            'items' => $items,
        ];
    }
}
