<?php
declare(strict_types=1);

namespace Foundry\Compiler\Passes;

use Foundry\Compiler\CompilationState;
use Foundry\Compiler\CompilerPass;
use Foundry\Config\ConfigValidationIssue;
use Foundry\Config\ConfigValidator;

final class ConfigValidationPass implements CompilerPass
{
    public function __construct(
        private readonly ConfigValidator $validator = new ConfigValidator(),
    ) {
    }

    public function name(): string
    {
        return 'config_validation';
    }

    public function run(CompilationState $state): void
    {
        $report = $this->validator->validateProject(
            paths: $state->paths,
            discoveredFeatures: $state->discoveredFeatures,
            discoveredDefinitions: $state->discoveredDefinitions,
            extensions: $state->extensions,
        );

        $state->configSchemas = $report->schemas;
        $state->configValidation = $report->toArray();

        foreach ($report->items as $issue) {
            if (!$issue instanceof ConfigValidationIssue) {
                continue;
            }

            $details = array_filter([
                'schema_id' => $issue->schemaId,
                'config_path' => $issue->configPath,
                'expected' => $issue->expected,
                'actual' => $issue->actual,
            ] + $issue->details, static fn (mixed $value): bool => $value !== null && $value !== '');

            match ($issue->severity) {
                'warning' => $state->diagnostics->warning(
                    code: $issue->code,
                    category: $issue->category,
                    message: $issue->message,
                    nodeId: $issue->nodeId,
                    sourcePath: $issue->sourcePath,
                    suggestedFix: $issue->suggestedFix,
                    pass: $this->name(),
                    details: $details,
                ),
                'info' => $state->diagnostics->info(
                    code: $issue->code,
                    category: $issue->category,
                    message: $issue->message,
                    nodeId: $issue->nodeId,
                    sourcePath: $issue->sourcePath,
                    suggestedFix: $issue->suggestedFix,
                    pass: $this->name(),
                    details: $details,
                ),
                default => $state->diagnostics->error(
                    code: $issue->code,
                    category: $issue->category,
                    message: $issue->message,
                    nodeId: $issue->nodeId,
                    sourcePath: $issue->sourcePath,
                    suggestedFix: $issue->suggestedFix,
                    pass: $this->name(),
                    details: $details,
                ),
            };
        }
    }
}
