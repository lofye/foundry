<?php
declare(strict_types=1);

namespace Foundry\CLI\Commands;

use Foundry\CLI\Command;
use Foundry\CLI\CommandContext;
use Foundry\Compiler\CompileOptions;
use Foundry\Compiler\Prompt\GraphPromptBuilder;
use Foundry\Support\FoundryError;

final class PromptCommand extends Command
{
    #[\Override]
    public function matches(array $args): bool
    {
        return ($args[0] ?? null) === 'prompt';
    }

    #[\Override]
    public function run(array $args, CommandContext $context): array
    {
        [$instruction, $dryRun, $featureContext] = $this->parse($args);
        if ($instruction === '') {
            throw new FoundryError(
                'CLI_PROMPT_INSTRUCTION_REQUIRED',
                'validation',
                [],
                'Prompt instruction is required.',
            );
        }

        $compiler = $context->graphCompiler();
        $compile = $compiler->compile(new CompileOptions());
        $verify = $context->graphVerifier()->verify();

        $builder = new GraphPromptBuilder($compiler->impactAnalyzer());
        $bundle = $builder->build($compile->graph, $instruction, $featureContext);

        $compileSummary = $compile->diagnostics->summary();
        $status = ((int) ($compileSummary['error'] ?? 0) > 0 || !$verify->ok) ? 1 : 0;

        return [
            'status' => $status,
            'message' => (string) ($bundle['prompt']['text'] ?? 'Prompt bundle generated.'),
            'payload' => [
                'instruction' => $instruction,
                'dry_run' => $dryRun,
                'feature_context' => $featureContext,
                'graph' => [
                    'graph_version' => $compile->graph->graphVersion(),
                    'framework_version' => $compile->graph->frameworkVersion(),
                    'compiled_at' => $compile->graph->compiledAt(),
                    'source_hash' => $compile->graph->sourceHash(),
                ],
                'preflight' => [
                    'compile_diagnostics' => [
                        'summary' => $compileSummary,
                        'items' => $compile->diagnostics->toArray(),
                    ],
                    'verify' => $verify->toArray(),
                ],
                'context' => $bundle['context_bundle'] ?? [],
                'selected_features' => $bundle['selected_features'] ?? [],
                'impact' => $bundle['impact'] ?? [],
                'prompt' => $bundle['prompt'] ?? [],
                'recommended_commands' => $bundle['recommended_commands'] ?? [],
            ],
        ];
    }

    /**
     * @param array<int,string> $args
     * @return array{0:string,1:bool,2:bool}
     */
    private function parse(array $args): array
    {
        $dryRun = false;
        $featureContext = false;
        $parts = [];

        foreach ($args as $index => $arg) {
            if ($index === 0) {
                continue;
            }

            if ($arg === '--dry-run') {
                $dryRun = true;
                continue;
            }

            if ($arg === '--feature-context') {
                $featureContext = true;
                continue;
            }

            $parts[] = $arg;
        }

        $instruction = trim(implode(' ', $parts));

        return [$instruction, $dryRun, $featureContext];
    }
}

