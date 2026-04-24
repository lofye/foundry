<?php

declare(strict_types=1);

namespace Foundry\Generate;

final readonly class GenerateWorkflowDefinition
{
    /**
     * @param array<string,mixed> $sharedContext
     * @param array<int,GenerateWorkflowStepDefinition> $steps
     */
    public function __construct(
        public string $id,
        public string $path,
        public array $sharedContext,
        public array $steps,
    ) {}

    /**
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'path' => $this->path,
            'shared_context' => $this->sharedContext,
            'steps' => array_values(array_map(
                static fn(GenerateWorkflowStepDefinition $step): array => $step->toArray(),
                $this->steps,
            )),
        ];
    }
}
