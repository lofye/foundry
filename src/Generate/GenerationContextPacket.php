<?php

declare(strict_types=1);

namespace Foundry\Generate;

use Foundry\Explain\ExplainModel;

final readonly class GenerationContextPacket
{
    /**
     * @param array<int,array<string,mixed>> $targets
     * @param array<string,mixed> $graphRelationships
     * @param array<int,string> $constraints
     * @param array<int,array<string,mixed>> $docs
     * @param array<int,string> $validationSteps
     * @param array<int,array<string,mixed>> $availableGenerators
     * @param array<int,array<string,mixed>> $installedPacks
     * @param array<int,string> $missingCapabilities
     * @param array<int,string> $suggestedPacks
     */
    public function __construct(
        public Intent $intent,
        public ExplainModel $model,
        public array $targets,
        public array $graphRelationships,
        public array $constraints,
        public array $docs,
        public array $validationSteps,
        public array $availableGenerators,
        public array $installedPacks,
        public array $missingCapabilities,
        public array $suggestedPacks,
    ) {}

    /**
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        return [
            'intent' => $this->intent->toArray(),
            'targets' => $this->targets,
            'graph_relationships' => $this->graphRelationships,
            'constraints' => $this->constraints,
            'docs' => $this->docs,
            'validation_steps' => $this->validationSteps,
            'available_generators' => $this->availableGenerators,
            'installed_packs' => $this->installedPacks,
            'missing_capabilities' => $this->missingCapabilities,
            'suggested_packs' => $this->suggestedPacks,
            'explain_model' => [
                'subject' => $this->model->subject,
                'confidence' => $this->model->confidence,
                'extensions' => $this->model->extensions,
            ],
        ];
    }
}
