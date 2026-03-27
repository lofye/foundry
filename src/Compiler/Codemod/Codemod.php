<?php

declare(strict_types=1);

namespace Foundry\Compiler\Codemod;

use Foundry\Support\Paths;

interface Codemod
{
    public function id(): string;

    public function description(): string;

    public function sourceType(): string;

    public function run(Paths $paths, bool $write = false, ?string $path = null): CodemodResult;
}
