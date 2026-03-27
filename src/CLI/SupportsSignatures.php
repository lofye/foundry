<?php

declare(strict_types=1);

namespace Foundry\CLI;

interface SupportsSignatures
{
    /**
     * @return list<string>
     */
    public function supportedSignatures(): array;

    public function supportsSignature(string $signature): bool;
}
