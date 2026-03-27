<?php

declare(strict_types=1);

namespace Foundry\Upgrade;

final readonly class UpgradeIssue
{
    /**
     * @param array<string,mixed> $affected
     * @param array<string,mixed> $details
     */
    public function __construct(
        public string $code,
        public string $severity,
        public string $category,
        public string $summary,
        public array $affected,
        public string $whyItMatters,
        public string $introducedIn,
        public string $targetVersion,
        public string $migration,
        public ?string $reference = null,
        public array $details = [],
    ) {}

    /**
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        return [
            'code' => $this->code,
            'severity' => $this->severity,
            'category' => $this->category,
            'summary' => $this->summary,
            'affected' => $this->affected,
            'why_it_matters' => $this->whyItMatters,
            'introduced_in' => $this->introducedIn,
            'target_version' => $this->targetVersion,
            'migration' => $this->migration,
            'reference' => $this->reference,
            'details' => $this->details,
        ];
    }
}
