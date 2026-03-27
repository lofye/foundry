<?php
declare(strict_types=1);

namespace Foundry\Pro\CLI;

use Foundry\CLI\Command;
use Foundry\CLI\CommandContext;
use Foundry\Compiler\CompileOptions;
use Foundry\Explain\ExplainOptions;
use Foundry\Explain\ExplainTarget;
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
        $this->requirePro('explain', ['architecture_explanation']);
        [$target, $targetKind, $options] = $this->parseExplainArgs($args);

        if ($target === '') {
            throw new FoundryError(
                'EXPLAIN_TARGET_REQUIRED',
                'validation',
                [],
                'Explain target is required.',
            );
        }

        $compiler = $context->graphCompiler();
        $graph = $compiler->loadGraph() ?? $compiler->compile(new CompileOptions())->graph;
        $response = (new ArchitectureExplainer(
            paths: $context->paths(),
            impactAnalyzer: $compiler->impactAnalyzer(),
            apiSurfaceRegistry: $context->apiSurfaceRegistry(),
            extensionRows: $context->extensionRegistry()->inspectRows(),
        ))->explain($graph, ExplainTarget::parse($target, $targetKind), $options);

        return [
            'status' => 0,
            'message' => $context->expectsJson() ? null : $response->rendered,
            'payload' => $context->expectsJson() ? $response->toArray() : null,
        ];
    }

    /**
     * @param array<int,string> $args
     * @return array{0:string,1:?string,2:ExplainOptions}
     */
    private function parseExplainArgs(array $args): array
    {
        $targetParts = [];
        $targetKind = null;
        $format = 'text';
        $deep = false;
        $includeDiagnostics = true;
        $includeNeighbors = true;
        $includeExecutionFlow = true;

        for ($index = 1; $index < count($args); $index++) {
            $arg = (string) $args[$index];

            if ($arg === '--markdown') {
                $format = 'markdown';
                continue;
            }

            if ($arg === '--deep') {
                $deep = true;
                continue;
            }

            if ($arg === '--no-diagnostics') {
                $includeDiagnostics = false;
                continue;
            }

            if ($arg === '--no-neighbors') {
                $includeNeighbors = false;
                continue;
            }

            if ($arg === '--neighbors') {
                $includeNeighbors = true;
                continue;
            }

            if ($arg === '--no-flow') {
                $includeExecutionFlow = false;
                continue;
            }

            if (str_starts_with($arg, '--type=')) {
                $targetKind = trim(substr($arg, strlen('--type=')));
                continue;
            }

            if ($arg === '--type') {
                $targetKind = trim((string) ($args[$index + 1] ?? ''));
                $index++;
                continue;
            }

            $targetParts[] = $arg;
        }

        return [
            trim(implode(' ', $targetParts)),
            $targetKind !== '' ? $targetKind : null,
            new ExplainOptions(
                format: $format,
                deep: $deep,
                includeDiagnostics: $includeDiagnostics,
                includeNeighbors: $includeNeighbors,
                includeExecutionFlow: $includeExecutionFlow,
                type: $targetKind !== '' ? $targetKind : null,
            ),
        ];
    }
}
