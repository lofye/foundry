<?php
declare(strict_types=1);

namespace Foundry\Config;

final readonly class ConfigValidationIssue
{
    /**
     * @param array<string,mixed> $details
     */
    public function __construct(
        public string $code,
        public string $severity,
        public string $category,
        public string $schemaId,
        public string $message,
        public ?string $sourcePath = null,
        public ?string $configPath = null,
        public ?string $expected = null,
        public ?string $actual = null,
        public ?string $suggestedFix = null,
        public ?string $nodeId = null,
        public array $details = [],
    ) {
    }

    /**
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        return [
            'code' => $this->code,
            'severity' => $this->severity,
            'category' => $this->category,
            'schema_id' => $this->schemaId,
            'message' => $this->message,
            'source_path' => $this->sourcePath,
            'config_path' => $this->configPath,
            'expected' => $this->expected,
            'actual' => $this->actual,
            'suggested_fix' => $this->suggestedFix,
            'node_id' => $this->nodeId,
            'details' => $this->details,
        ];
    }
}
