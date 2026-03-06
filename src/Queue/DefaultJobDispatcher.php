<?php
declare(strict_types=1);

namespace Forge\Queue;

use Forge\Observability\TraceRecorder;
use Forge\Schema\JsonSchemaValidator;
use Forge\Schema\ValidationResult;
use Forge\Support\ForgeError;

final class DefaultJobDispatcher implements JobDispatcher
{
    public function __construct(
        private readonly JobRegistry $jobs,
        private readonly QueueDriver $driver,
        private readonly ?TraceRecorder $traceRecorder = null,
    ) {
    }

    public function dispatch(string $jobName, array $payload): void
    {
        $definition = $this->jobs->get($jobName);
        $validation = $this->validatePayload($definition, $payload);
        if (!$validation->isValid) {
            throw new ForgeError('JOB_PAYLOAD_INVALID', 'validation', ['job' => $jobName], 'Job payload does not match schema.');
        }

        $this->driver->enqueue($definition->queue, $jobName, $payload);
        $this->traceRecorder?->record($jobName, 'queue', 'job_dispatch', ['queue' => $definition->queue]);
    }

    /**
     * @param array<string,mixed> $payload
     */
    private function validatePayload(JobDefinition $definition, array $payload): ValidationResult
    {
        $tmpFile = tempnam(sys_get_temp_dir(), 'forge-job-schema-');
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
