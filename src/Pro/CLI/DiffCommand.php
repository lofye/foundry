<?php

declare(strict_types=1);

namespace Foundry\Pro\CLI;

use Foundry\CLI\Command;
use Foundry\CLI\CommandContext;
use Foundry\Compiler\CompileOptions;
use Foundry\Pro\CLI\Concerns\InteractsWithPro;
use Foundry\Pro\GraphDiffAnalyzer;

final class DiffCommand extends Command
{
    use InteractsWithPro;

    #[\Override]
    public function supportedSignatures(): array
    {
        return ['diff'];
    }

    #[\Override]
    public function matches(array $args): bool
    {
        return ($args[0] ?? null) === 'diff';
    }

    #[\Override]
    public function run(array $args, CommandContext $context): array
    {
        $license = $this->requirePro('diff', ['graph_diffing']);

        $compiler = $context->graphCompiler();
        $baseline = $compiler->loadGraph();
        $current = $compiler->compile(new CompileOptions(emit: false, useCache: false))->graph;

        $payload = (new GraphDiffAnalyzer())->diff($baseline, $current);
        $payload['pro'] = ['license' => $license];

        return [
            'status' => 0,
            'message' => $context->expectsJson() ? null : $this->renderHumanReport($payload),
            'payload' => $context->expectsJson() ? $payload : null,
        ];
    }

    /**
     * @param array<string,mixed> $payload
     */
    private function renderHumanReport(array $payload): string
    {
        $summary = is_array($payload['summary'] ?? null) ? $payload['summary'] : [];

        return sprintf(
            'Graph diff: +%d node(s), -%d node(s), %d changed node(s); +%d edge(s), -%d edge(s), %d changed edge(s).',
            (int) ($summary['added_nodes'] ?? 0),
            (int) ($summary['removed_nodes'] ?? 0),
            (int) ($summary['changed_nodes'] ?? 0),
            (int) ($summary['added_edges'] ?? 0),
            (int) ($summary['removed_edges'] ?? 0),
            (int) ($summary['changed_edges'] ?? 0),
        );
    }
}
