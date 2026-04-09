<?php

declare(strict_types=1);

namespace Foundry\Context;

final readonly class ExecutionResult
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
        public bool $repairAttempted,
        public bool $repairSuccessful,
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
     *     repair_attempted:bool,
     *     repair_successful:bool,
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
            'repair_attempted' => $this->repairAttempted,
            'repair_successful' => $this->repairSuccessful,
            'actions_taken' => array_values($this->actionsTaken),
            'issues' => array_values($this->issues),
            'required_actions' => array_values($this->requiredActions),
        ];
    }
}
