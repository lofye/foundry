<?php

declare(strict_types=1);

namespace Foundry\Queue;

use Foundry\Support\FoundryError;

final class JobRegistry
{
    /**
     * @var array<string,JobDefinition>
     */
    private array $jobs = [];

    public function register(JobDefinition $job): void
    {
        $this->jobs[$job->name] = $job;
    }

    public function has(string $name): bool
    {
        return isset($this->jobs[$name]);
    }

    public function get(string $name): JobDefinition
    {
        if (!isset($this->jobs[$name])) {
            throw new FoundryError('JOB_NOT_FOUND', 'not_found', ['job' => $name], 'Job not found.');
        }

        return $this->jobs[$name];
    }

    /**
     * @return array<string,JobDefinition>
     */
    public function all(): array
    {
        ksort($this->jobs);

        return $this->jobs;
    }
}
