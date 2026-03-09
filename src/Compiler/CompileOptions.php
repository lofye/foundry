<?php
declare(strict_types=1);

namespace Foundry\Compiler;

final readonly class CompileOptions
{
    public function __construct(
        public ?string $feature = null,
        public bool $changedOnly = false,
        public bool $emit = true,
    ) {
    }

    public function mode(): string
    {
        if ($this->feature !== null && $this->feature !== '') {
            return 'feature';
        }

        if ($this->changedOnly) {
            return 'changed_only';
        }

        return 'full';
    }
}
