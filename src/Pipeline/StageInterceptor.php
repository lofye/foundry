<?php
declare(strict_types=1);

namespace Foundry\Pipeline;

interface StageInterceptor
{
    public function id(): string;

    public function stage(): string;

    public function priority(): int;

    public function handle(PipelineExecutionState $state): void;

    public function isDangerous(): bool;
}

