<?php

declare(strict_types=1);

namespace Foundry\Context;

final readonly class ContextDoctorDiagnosticRuleContext
{
    /**
     * @param array<string,array<string,mixed>> $files
     * @param array<string,string> $contents
     */
    public function __construct(
        public string $feature,
        public array $files,
        public bool $featureHasExecutionSpecs,
        public array $contents = [],
    ) {}

    /**
     * @return list<ContextDoctorDiagnosticTarget>
     */
    public function missingCanonicalTargets(): array
    {
        $targets = [];

        foreach (['spec', 'state', 'decisions'] as $bucket) {
            $file = (array) ($this->files[$bucket] ?? []);
            if ((bool) ($file['exists'] ?? false)) {
                continue;
            }

            $targets[] = new ContextDoctorDiagnosticTarget(
                bucket: $bucket,
                filePath: (string) ($file['path'] ?? ''),
            );
        }

        return $targets;
    }

    public function hasUsableFiles(string ...$buckets): bool
    {
        foreach ($buckets as $bucket) {
            $file = (array) ($this->files[$bucket] ?? []);
            if (!(bool) ($file['exists'] ?? false) || !(bool) ($file['valid'] ?? false)) {
                return false;
            }
        }

        return true;
    }

    public function filePath(string $bucket): string
    {
        return (string) (((array) ($this->files[$bucket] ?? []))['path'] ?? '');
    }

    public function fileContents(string $bucket): string
    {
        return (string) ($this->contents[$bucket] ?? '');
    }
}
