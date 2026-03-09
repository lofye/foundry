<?php
declare(strict_types=1);

namespace Foundry\Compiler\Migration;

final readonly class SpecMigrationResult
{
    /**
     * @param array<int,array<string,mixed>> $changes
     * @param array<int,array<string,mixed>> $diagnostics
     * @param array<int,array<string,mixed>> $plans
     */
    public function __construct(
        public bool $written,
        public array $changes,
        public array $diagnostics,
        public array $plans = [],
        public ?string $pathFilter = null,
    ) {
    }

    /**
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        return [
            'written' => $this->written,
            'mode' => $this->written ? 'write' : 'dry-run',
            'path_filter' => $this->pathFilter,
            'plans' => $this->plans,
            'changes' => $this->changes,
            'diagnostics' => $this->diagnostics,
        ];
    }
}
