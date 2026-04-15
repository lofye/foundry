<?php

declare(strict_types=1);

namespace Foundry\Context;

final readonly class ContextDoctorDiagnosticTarget
{
    public function __construct(
        public string $bucket,
        public string $filePath,
    ) {}
}
