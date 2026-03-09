<?php
declare(strict_types=1);

namespace Foundry\Compiler\Diagnostics;

final readonly class Diagnostic
{
    /**
     * @param array<int,string> $relatedNodes
     */
    public function __construct(
        public string $id,
        public string $code,
        public string $severity,
        public string $category,
        public string $message,
        public ?string $nodeId = null,
        public ?string $sourcePath = null,
        public ?int $sourceLine = null,
        public array $relatedNodes = [],
        public ?string $suggestedFix = null,
        public ?string $pass = null,
    ) {
    }

    /**
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'severity' => $this->severity,
            'category' => $this->category,
            'message' => $this->message,
            'node_id' => $this->nodeId,
            'source_path' => $this->sourcePath,
            'source_line' => $this->sourceLine,
            'related_nodes' => $this->relatedNodes,
            'suggested_fix' => $this->suggestedFix,
            'pass' => $this->pass,
        ];
    }
}
