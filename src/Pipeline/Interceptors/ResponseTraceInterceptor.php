<?php
declare(strict_types=1);

namespace Foundry\Pipeline\Interceptors;

use Foundry\Pipeline\PipelineExecutionState;
use Foundry\Pipeline\StageInterceptor;

final class ResponseTraceInterceptor implements StageInterceptor
{
    public function id(): string
    {
        return 'trace.response_send';
    }

    public function stage(): string
    {
        return 'response_send';
    }

    public function priority(): int
    {
        return 50;
    }

    public function handle(PipelineExecutionState $state): void
    {
        $status = isset($state->metadata['response_status']) ? (int) $state->metadata['response_status'] : 200;
        $state->trace->record(
            $state->feature->name,
            'pipeline',
            'response_send',
            ['status' => $status],
        );
    }

    public function isDangerous(): bool
    {
        return false;
    }
}

