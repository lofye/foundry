<?php
declare(strict_types=1);

namespace Foundry\Observability;

interface Logger
{
    /**
     * @param array<string,mixed> $context
     */
    public function log(string $level, string $message, array $context = []): void;
}
