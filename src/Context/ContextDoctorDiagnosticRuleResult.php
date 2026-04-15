<?php

declare(strict_types=1);

namespace Foundry\Context;

final readonly class ContextDoctorDiagnosticRuleResult
{
    /**
     * @param list<ContextDoctorDiagnosticTarget> $targets
     * @param list<string> $requiredActions
     */
    public function __construct(
        public string $code,
        public string $message,
        public array $targets,
        public array $requiredActions = [],
        public bool $requiresRepair = true,
    ) {}

    /**
     * @return list<string>
     */
    public function targetBuckets(): array
    {
        return array_values(array_map(
            static fn(ContextDoctorDiagnosticTarget $target): string => $target->bucket,
            $this->targets,
        ));
    }

    /**
     * @return list<string>
     */
    public function targetFilePaths(): array
    {
        return array_values(array_map(
            static fn(ContextDoctorDiagnosticTarget $target): string => $target->filePath,
            $this->targets,
        ));
    }
}
