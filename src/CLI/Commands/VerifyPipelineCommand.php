<?php
declare(strict_types=1);

namespace Foundry\CLI\Commands;

use Foundry\CLI\Command;
use Foundry\CLI\CommandContext;
use Foundry\Compiler\CompileOptions;
use Foundry\Pipeline\PipelineIntegrityInspector;

final class VerifyPipelineCommand extends Command
{
    #[\Override]
    public function matches(array $args): bool
    {
        return ($args[0] ?? null) === 'verify' && ($args[1] ?? null) === 'pipeline';
    }

    #[\Override]
    public function run(array $args, CommandContext $context): array
    {
        $compiler = $context->graphCompiler();
        $graph = $compiler->loadGraph() ?? $compiler->compile(new CompileOptions())->graph;

        $inspection = (new PipelineIntegrityInspector())->inspect($graph);
        $errors = [];
        $warnings = [];
        foreach ((array) ($inspection['issues'] ?? []) as $issue) {
            if (!is_array($issue)) {
                continue;
            }

            $severity = (string) ($issue['severity'] ?? 'warning');
            $message = (string) ($issue['message'] ?? '');
            if ($message === '') {
                continue;
            }

            if ($severity === 'error') {
                $errors[] = $message;
            } elseif ($severity === 'warning') {
                $warnings[] = $message;
            }
        }
        sort($errors);
        $errors = array_values(array_unique($errors));
        sort($warnings);
        $warnings = array_values(array_unique($warnings));

        $ok = $errors === [];

        return [
            'status' => $ok ? 0 : 1,
            'message' => $ok ? 'Pipeline verification passed.' : 'Pipeline verification failed.',
            'payload' => [
                'ok' => $ok,
                'errors' => $errors,
                'warnings' => $warnings,
                'summary' => is_array($inspection['summary'] ?? null) ? $inspection['summary'] : [],
            ],
        ];
    }
}
