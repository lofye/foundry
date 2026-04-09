<?php

declare(strict_types=1);

namespace Foundry\Context;

final readonly class AlignmentResult
{
    /**
     * @param array<int,AlignmentIssue> $issues
     * @param array<int,string> $required_actions
     */
    public function __construct(
        public string $status,
        public array $issues = [],
        public array $required_actions = [],
    ) {}

    /**
     * @return array{status:string,feature:string,can_proceed:bool,requires_repair:bool,issues:list<array<string,mixed>>,required_actions:list<string>}
     */
    public function toArray(string $feature): array
    {
        $readiness = ContextExecutionReadiness::fromAlignmentStatus($this->status);

        return [
            'status' => $this->status,
            'feature' => $feature,
            'can_proceed' => $readiness['can_proceed'],
            'requires_repair' => $readiness['requires_repair'],
            'issues' => array_values(array_map(
                static fn(AlignmentIssue $issue): array => $issue->toArray(),
                $this->issues,
            )),
            'required_actions' => array_values($this->required_actions),
        ];
    }
}
