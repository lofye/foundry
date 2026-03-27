<?php

declare(strict_types=1);

namespace Foundry\CLI\Commands;

use Foundry\CLI\Command;
use Foundry\CLI\CommandContext;

final class VerifyCompatibilityCommand extends Command
{
    #[\Override]
    public function supportedSignatures(): array
    {
        return ['verify extensions', 'verify compatibility'];
    }

    #[\Override]
    public function matches(array $args): bool
    {
        return ($args[0] ?? null) === 'verify'
            && $this->supportsSignature('verify ' . (string) ($args[1] ?? ''));
    }

    #[\Override]
    public function run(array $args, CommandContext $context): array
    {
        $target = (string) ($args[1] ?? 'compatibility');
        $compiler = $context->graphCompiler();
        $report = $context->extensionRegistry()->compatibilityReport(
            frameworkVersion: $compiler->frameworkVersion(),
            graphVersion: \Foundry\Compiler\GraphCompiler::GRAPH_VERSION,
        );

        $diagnostics = $report->diagnostics;

        $ok = true;
        foreach ($diagnostics as $row) {
            if ((string) ($row['severity'] ?? '') === 'error') {
                $ok = false;
                break;
            }
        }

        return [
            'status' => $ok ? 0 : 1,
            'message' => $ok ? ucfirst($target) . ' verification passed.' : ucfirst($target) . ' verification failed.',
            'payload' => [
                'ok' => $ok,
                'target' => $target,
                'diagnostics' => $diagnostics,
                'version_matrix' => $report->versionMatrix,
                'lifecycle' => $report->lifecycle,
                'load_order' => $report->loadOrder,
            ],
        ];
    }
}
