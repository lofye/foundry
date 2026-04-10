<?php

declare(strict_types=1);

namespace Foundry\Context;

final readonly class PlanResult
{
    /**
     * @param list<string> $actionsTaken
     * @param list<array<string,mixed>> $issues
     * @param list<string> $requiredActions
     */
    public function __construct(
        public string $feature,
        public string $status,
        public bool $canProceed,
        public bool $requiresRepair,
        public ?string $specId = null,
        public ?string $specPath = null,
        public array $actionsTaken = [],
        public array $issues = [],
        public array $requiredActions = [],
    ) {}

    /**
     * @return array{
     *     feature:string,
     *     status:string,
     *     can_proceed:bool,
     *     requires_repair:bool,
     *     spec_id:string|null,
     *     spec_path:string|null,
     *     actions_taken:list<string>,
     *     issues:list<array<string,mixed>>,
     *     required_actions:list<string>
     * }
     */
    public function toArray(): array
    {
        return [
            'feature' => $this->feature,
            'status' => $this->status,
            'can_proceed' => $this->canProceed,
            'requires_repair' => $this->requiresRepair,
            'spec_id' => $this->specId,
            'spec_path' => $this->specPath,
            'actions_taken' => array_values($this->actionsTaken),
            'issues' => array_values($this->issues),
            'required_actions' => array_values($this->requiredActions),
        ];
    }
}
