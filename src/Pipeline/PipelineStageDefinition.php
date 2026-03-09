<?php
declare(strict_types=1);

namespace Foundry\Pipeline;

final readonly class PipelineStageDefinition
{
    public function __construct(
        public string $name,
        public ?string $afterStage = null,
        public ?string $beforeStage = null,
        public int $priority = 100,
        public ?string $extension = null,
    ) {
    }

    /**
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'after_stage' => $this->afterStage,
            'before_stage' => $this->beforeStage,
            'priority' => $this->priority,
            'extension' => $this->extension,
        ];
    }
}

