<?php

declare(strict_types=1);

namespace Foundry\Packs;

interface PackServiceProvider
{
    public function register(PackContext $context): void;
}
