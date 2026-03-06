<?php
declare(strict_types=1);

namespace Forge\Queue;

use Forge\Observability\TraceRecorder;
use Forge\Support\ForgeError;

final class Worker
{
    /**
     * @param array<string,callable(array<string,mixed>):void> $handlers
     */
    public function __construct(
        private readonly QueueDriver $driver,
        private readonly JobRegistry $jobs,
        private readonly array $handlers,
        private readonly ?TraceRecorder $traceRecorder = null,
    ) {
    }

    public function process(string $queue, int $limit = 1): int
    {
        $processed = 0;

        while ($processed < $limit) {
            $job = $this->driver->dequeue($queue);
            if ($job === null) {
                break;
            }

            $processed++;
            $name = $job['job'];
            $payload = $job['payload'];

            $definition = $this->jobs->get($name);
            $handler = $this->handlers[$name] ?? null;
            if ($handler === null) {
                throw new ForgeError('JOB_HANDLER_NOT_FOUND', 'runtime', ['job' => $name], 'Job handler not found.');
            }

            $attempt = 1;
            $success = false;

            while ($attempt <= $definition->retry->maxAttempts) {
                try {
                    $handler($payload);
                    $success = true;
                    $this->traceRecorder?->record($name, 'queue', 'job_processed', ['attempt' => $attempt]);
                    break;
                } catch (\Throwable $e) {
                    if ($attempt >= $definition->retry->maxAttempts) {
                        $this->traceRecorder?->record($name, 'queue', 'job_failed', ['attempt' => $attempt, 'exception' => $e::class]);
                        throw $e;
                    }

                    $attempt++;
                }
            }

            if (!$success) {
                throw new ForgeError('JOB_RETRY_EXHAUSTED', 'runtime', ['job' => $name], 'Job retry exhausted.');
            }
        }

        return $processed;
    }
}
