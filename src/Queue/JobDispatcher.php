<?php
declare(strict_types=1);

namespace Foundry\Queue;

interface JobDispatcher
{
    /**
     * @param array<string,mixed> $payload
     */
    public function dispatch(string $jobName, array $payload): void;
}
