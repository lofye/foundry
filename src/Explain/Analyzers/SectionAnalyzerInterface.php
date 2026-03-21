<?php
declare(strict_types=1);

namespace Foundry\Explain\Analyzers;

use Foundry\Explain\ExplainContext;
use Foundry\Explain\ExplainOptions;
use Foundry\Explain\ExplainSubject;

interface SectionAnalyzerInterface
{
    public function supports(ExplainSubject $subject): bool;

    public function sectionId(): string;

    /**
     * @return array<string,mixed>
     */
    public function analyze(ExplainSubject $subject, ExplainContext $context, ExplainOptions $options): array;
}
