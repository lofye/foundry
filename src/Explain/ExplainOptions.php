<?php
declare(strict_types=1);

namespace Foundry\Explain;

final readonly class ExplainOptions
{
    public function __construct(
        public string $format = 'text',
        public bool $deep = false,
        public bool $includeDiagnostics = true,
        public bool $includeNeighbors = true,
        public bool $includeExecutionFlow = true,
        public bool $includeRelatedCommands = true,
        public bool $includeRelatedDocs = true,
        public ?string $type = null,
    ) {
    }

    /**
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        return [
            'format' => $this->format,
            'deep' => $this->deep,
            'include_diagnostics' => $this->includeDiagnostics,
            'include_neighbors' => $this->includeNeighbors,
            'include_execution_flow' => $this->includeExecutionFlow,
            'include_related_commands' => $this->includeRelatedCommands,
            'include_related_docs' => $this->includeRelatedDocs,
            'type' => $this->type,
        ];
    }
}
