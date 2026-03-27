<?php
declare(strict_types=1);

namespace Foundry\Explain\Analyzers;

final readonly class SubjectAnalysisResult
{
    /**
     * @param array<int,string> $responsibilities
     * @param array<string,mixed> $summaryInputs
     * @param array<int,array<string,mixed>> $sections
     */
    public function __construct(
        public array $responsibilities = [],
        public array $summaryInputs = [],
        public array $sections = [],
    ) {
    }
}
