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
     * @return array{status:string,feature:string,issues:list<array<string,mixed>>,required_actions:list<string>}
     */
    public function toArray(string $feature): array
    {
        return [
            'status' => $this->status,
            'feature' => $feature,
            'issues' => array_values(array_map(
                static fn(AlignmentIssue $issue): array => $issue->toArray(),
                $this->issues,
            )),
            'required_actions' => array_values($this->required_actions),
        ];
    }
}
