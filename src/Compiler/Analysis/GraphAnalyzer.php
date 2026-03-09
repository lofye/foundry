<?php
declare(strict_types=1);

namespace Foundry\Compiler\Analysis;

use Foundry\Compiler\ApplicationGraph;
use Foundry\Compiler\Diagnostics\DiagnosticBag;

interface GraphAnalyzer
{
    public function id(): string;

    public function description(): string;

    /**
     * @return array<string,mixed>
     */
    public function analyze(ApplicationGraph $graph, AnalyzerContext $context, DiagnosticBag $diagnostics): array;
}

