<?php
declare(strict_types=1);

namespace Foundry\Compiler;

interface CompilerPass
{
    public function name(): string;

    public function run(CompilationState $state): void;
}
