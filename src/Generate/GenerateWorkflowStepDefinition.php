<?php

declare(strict_types=1);

namespace Foundry\Generate;

final readonly class GenerateWorkflowStepDefinition
{
    /**
     * @param array<int,string> $packHints
     * @param array<int,string> $dependencies
     */
    public function __construct(
        public string $id,
        public string $description,
        public string $rawIntent,
        public string $mode,
        public ?string $target = null,
        public array $packHints = [],
        public array $dependencies = [],
    ) {}

    /**
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'description' => $this->description,
            'intent' => $this->rawIntent,
            'mode' => $this->mode,
            'target' => $this->target,
            'packs' => $this->packHints,
            'dependencies' => $this->dependencies,
        ];
    }
}
