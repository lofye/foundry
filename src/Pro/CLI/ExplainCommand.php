<?php
declare(strict_types=1);

namespace Foundry\Pro\CLI;

use Foundry\CLI\Command;
use Foundry\CLI\CommandContext;
use Foundry\Compiler\CompileOptions;
use Foundry\Pro\ArchitectureExplainer;
use Foundry\Pro\CLI\Concerns\InteractsWithPro;
use Foundry\Support\FoundryError;

final class ExplainCommand extends Command
{
    use InteractsWithPro;

    #[\Override]
    public function matches(array $args): bool
    {
        return ($args[0] ?? null) === 'explain';
    }

    #[\Override]
    public function run(array $args, CommandContext $context): array
    {
        $license = $this->requirePro('explain', ['architecture_explanation']);
        $target = trim(implode(' ', array_slice($args, 1)));

        if ($target === '') {
            throw new FoundryError(
                'PRO_EXPLAIN_TARGET_REQUIRED',
                'validation',
                [],
                'Explain target is required.',
            );
        }

        $compiler = $context->graphCompiler();
        $graph = $compiler->loadGraph() ?? $compiler->compile(new CompileOptions(emit: false))->graph;
        $payload = (new ArchitectureExplainer($compiler->impactAnalyzer()))->explain($graph, $target);
        $payload['pro'] = ['license' => $license];

        return [
            'status' => 0,
            'message' => $context->expectsJson() ? null : (string) ($payload['explanation'] ?? 'Architecture explanation prepared.'),
            'payload' => $context->expectsJson() ? $payload : null,
        ];
    }
}
