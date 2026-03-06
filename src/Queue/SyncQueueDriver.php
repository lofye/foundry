<?php
declare(strict_types=1);

namespace Foundry\Queue;

final class SyncQueueDriver implements QueueDriver
{
    /**
     * @var array<string,array<int,array{job:string,payload:array<string,mixed>}>>
     */
    private array $queues = [];

    #[\Override]
    public function enqueue(string $queue, string $jobName, array $payload): void
    {
        $this->queues[$queue] ??= [];
        $this->queues[$queue][] = [
            'job' => $jobName,
            'payload' => $payload,
        ];
    }

    #[\Override]
    public function dequeue(string $queue): ?array
    {
        $this->queues[$queue] ??= [];

        return array_shift($this->queues[$queue]);
    }

    #[\Override]
    public function inspect(string $queue): array
    {
        $this->queues[$queue] ??= [];

        return $this->queues[$queue];
    }
}
