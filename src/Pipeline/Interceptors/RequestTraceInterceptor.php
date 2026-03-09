<?php
declare(strict_types=1);

namespace Foundry\Pipeline\Interceptors;

use Foundry\Pipeline\PipelineExecutionState;
use Foundry\Pipeline\StageInterceptor;

final class RequestTraceInterceptor implements StageInterceptor
{
    public function id(): string
    {
        return 'trace.request_received';
    }

    public function stage(): string
    {
        return 'request_received';
    }

    public function priority(): int
    {
        return 50;
    }

    public function handle(PipelineExecutionState $state): void
    {
        $state->trace->record(
            $state->feature->name,
            'pipeline',
            'request_received',
            ['method' => $state->request->method(), 'path' => $state->request->path()],
        );
    }

    public function isDangerous(): bool
    {
        return false;
    }
}

