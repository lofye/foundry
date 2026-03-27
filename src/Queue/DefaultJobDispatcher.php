<?php

declare(strict_types=1);

namespace Foundry\Queue;

use Foundry\Observability\TraceRecorder;
use Foundry\Schema\JsonSchemaValidator;
use Foundry\Schema\ValidationResult;
use Foundry\Support\FoundryError;

final class DefaultJobDispatcher implements JobDispatcher
{
    public function __construct(
        private readonly JobRegistry $jobs,
        private readonly QueueDriver $driver,
        private readonly ?TraceRecorder $traceRecorder = null,
    ) {}

    #[\Override]
    public function dispatch(string $jobName, array $payload): void
    {
        $definition = $this->jobs->get($jobName);
        $validation = $this->validatePayload($definition, $payload);
        if (!$validation->isValid) {
            throw new FoundryError('JOB_PAYLOAD_INVALID', 'validation', ['job' => $jobName], 'Job payload does not match schema.');
        }

        $this->driver->enqueue($definition->queue, $jobName, $payload);
        $this->traceRecorder?->record($jobName, 'queue', 'job_dispatch', ['queue' => $definition->queue]);
    }

    /**
     * @param array<string,mixed> $payload
     */
    private function validatePayload(JobDefinition $definition, array $payload): ValidationResult
    {
        $tmpFile = tempnam(sys_get_temp_dir(), 'foundry-job-schema-');
        if ($tmpFile === false) {
            return ValidationResult::valid();
        }

        file_put_contents($tmpFile, json_encode($definition->inputSchema, JSON_UNESCAPED_SLASHES));
        $validator = new JsonSchemaValidator();
        $result = $validator->validate($payload, $tmpFile);
        @unlink($tmpFile);

        return $result;
    }
}
