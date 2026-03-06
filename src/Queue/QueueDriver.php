<?php
declare(strict_types=1);

namespace Foundry\Queue;

interface QueueDriver
{
    /**
     * @param array<string,mixed> $payload
     */
    public function enqueue(string $queue, string $jobName, array $payload): void;

    /**
     * @return array{job:string,payload:array<string,mixed>}|null
     */
    public function dequeue(string $queue): ?array;

    /**
     * @return array<int,array{job:string,payload:array<string,mixed>}>
     */
    public function inspect(string $queue): array;
}
