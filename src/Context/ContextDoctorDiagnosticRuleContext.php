<?php

declare(strict_types=1);

namespace Foundry\Context;

final readonly class ContextDoctorDiagnosticRuleContext
{
    /**
     * @param array<string,array<string,mixed>> $files
     */
    public function __construct(
        public string $feature,
        public array $files,
        public bool $featureHasExecutionSpecs,
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
}
