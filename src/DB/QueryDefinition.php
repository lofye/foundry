<?php

declare(strict_types=1);

namespace Foundry\DB;

final readonly class QueryDefinition
{
    /**
     * @param array<int,string> $placeholders
     */
    public function __construct(
        public readonly string $feature,
        public readonly string $name,
        public readonly string $sql,
        public readonly array $placeholders,
    ) {}

    /**
     * @return array<string,mixed>
     */
    public function signature(): array
    {
        return [
            'feature' => $this->feature,
            'name' => $this->name,
            'placeholders' => $this->placeholders,
        ];
    }
}
