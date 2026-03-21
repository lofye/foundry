<?php
declare(strict_types=1);

namespace Foundry\Explain;

final readonly class ExplanationPlan
{
    /**
     * @param array<string,mixed> $subject
     * @param array<string,mixed> $summary
     * @param array<int,array<string,mixed>> $sections
     * @param array<string,mixed> $relationships
     * @param array<string,mixed> $executionFlow
     * @param array<string,mixed> $diagnostics
     * @param array<int,string> $relatedCommands
     * @param array<int,array<string,mixed>> $relatedDocs
     * @param array<string,mixed> $metadata
     */
    public function __construct(
        public array $subject,
        public array $summary,
        public array $sections,
        public array $relationships,
        public array $executionFlow,
        public array $diagnostics,
        public array $relatedCommands,
        public array $relatedDocs,
        public array $metadata,
    ) {
    }

    /**
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        return [
            'subject' => $this->subject,
            'summary' => $this->summary,
            'sections' => $this->sections,
            'relationships' => $this->relationships,
            'execution_flow' => $this->executionFlow,
            'diagnostics' => $this->diagnostics,
            'related_commands' => $this->relatedCommands,
            'related_docs' => $this->relatedDocs,
            'metadata' => $this->metadata,
        ];
    }
}
