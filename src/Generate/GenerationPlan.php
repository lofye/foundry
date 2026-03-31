<?php

declare(strict_types=1);

namespace Foundry\Generate;

use Foundry\Support\FoundryError;

final readonly class GenerationPlan
{
    /**
     * @param array<int,array<string,mixed>> $actions
     * @param array<int,string> $affectedFiles
     * @param array<int,string> $risks
     * @param array<int,string> $validations
     * @param array<string,mixed> $metadata
     * @param array<string,mixed> $confidence
     */
    public function __construct(
        public array $actions,
        public array $affectedFiles,
        public array $risks,
        public array $validations,
        public string $origin,
        public string $generatorId,
        public ?string $extension = null,
        public array $metadata = [],
        public array $confidence = [],
    ) {}

    /**
     * @param array<int,self> $plans
     */
    public static function merge(array $plans): self
    {
        if ($plans === []) {
            throw new FoundryError(
                'GENERATE_PLAN_EMPTY',
                'validation',
                [],
                'At least one generation plan is required.',
            );
        }

        $first = $plans[0];
        $origin = $first->origin;
        $extension = $first->extension;
        $generatorIds = [];
        $actions = [];
        $affectedFiles = [];
        $risks = [];
        $validations = [];
        $metadata = [
            'merged_generators' => [],
        ];
        $seenActionKeys = [];

        foreach ($plans as $plan) {
            if ($plan->origin !== $origin || $plan->extension !== $extension) {
                throw new FoundryError(
                    'GENERATE_GENERATOR_CONFLICT',
                    'validation',
                    [
                        'plans' => array_map(
                            static fn(self $candidate): array => [
                                'generator_id' => $candidate->generatorId,
                                'origin' => $candidate->origin,
                                'extension' => $candidate->extension,
                            ],
                            $plans,
                        ),
                    ],
                    'Generation plans from different origins cannot be merged deterministically.',
                );
            }

            $generatorIds[] = $plan->generatorId;
            $metadata['merged_generators'][] = $plan->generatorId;

            foreach ($plan->actions as $action) {
                $actionKey = (string) ($action['type'] ?? '') . ':' . (string) ($action['path'] ?? '');
                if ($actionKey === ':') {
                    $actionKey = md5(serialize($action));
                }

                if (isset($seenActionKeys[$actionKey])) {
                    throw new FoundryError(
                        'GENERATE_PLAN_CONFLICT',
                        'validation',
                        [
                            'generator_id' => $plan->generatorId,
                            'conflict' => $action,
                        ],
                        'Generation plans would write the same action twice.',
                    );
                }

                $seenActionKeys[$actionKey] = true;
                $actions[] = $action;
            }

            $affectedFiles = array_merge($affectedFiles, $plan->affectedFiles);
            $risks = array_merge($risks, $plan->risks);
            $validations = array_merge($validations, $plan->validations);
            $metadata += $plan->metadata;
        }

        $affectedFiles = array_values(array_unique(array_map('strval', $affectedFiles)));
        sort($affectedFiles);
        $risks = array_values(array_unique(array_map('strval', $risks)));
        sort($risks);
        $validations = array_values(array_unique(array_map('strval', $validations)));
        sort($validations);
        $metadata['merged_generators'] = array_values(array_unique(array_map('strval', (array) $metadata['merged_generators'])));
        sort($metadata['merged_generators']);

        return new self(
            actions: $actions,
            affectedFiles: $affectedFiles,
            risks: $risks,
            validations: $validations,
            origin: $origin,
            generatorId: implode('+', array_values(array_unique($generatorIds))),
            extension: $extension,
            metadata: $metadata,
        );
    }

    /**
     * @param array<string,mixed> $confidence
     */
    public function withConfidence(array $confidence): self
    {
        return new self(
            actions: $this->actions,
            affectedFiles: $this->affectedFiles,
            risks: $this->risks,
            validations: $this->validations,
            origin: $this->origin,
            generatorId: $this->generatorId,
            extension: $this->extension,
            metadata: $this->metadata,
            confidence: $confidence,
        );
    }

    /**
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        return [
            'actions' => $this->actions,
            'affected_files' => $this->affectedFiles,
            'risks' => $this->risks,
            'validations' => $this->validations,
            'origin' => $this->origin,
            'generator_id' => $this->generatorId,
            'extension' => $this->extension,
            'confidence' => $this->confidence,
            'metadata' => $this->metadata,
        ];
    }
}
