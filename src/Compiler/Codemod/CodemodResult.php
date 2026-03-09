<?php
declare(strict_types=1);

namespace Foundry\Compiler\Codemod;

final readonly class CodemodResult
{
    /**
     * @param array<int,array<string,mixed>> $changes
     * @param array<int,array<string,mixed>> $diagnostics
     */
    public function __construct(
        public string $codemod,
        public bool $written,
        public array $changes,
        public array $diagnostics,
        public ?string $pathFilter = null,
    ) {
    }

    /**
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        return [
            'codemod' => $this->codemod,
            'written' => $this->written,
            'path_filter' => $this->pathFilter,
            'changes' => $this->changes,
            'diagnostics' => $this->diagnostics,
        ];
    }
}
