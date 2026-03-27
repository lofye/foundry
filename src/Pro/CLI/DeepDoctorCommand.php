<?php
declare(strict_types=1);

namespace Foundry\Pro\CLI;

use Foundry\CLI\Command;
use Foundry\CLI\CommandContext;
use Foundry\CLI\Commands\DoctorCommand;
use Foundry\Compiler\CompileOptions;
use Foundry\Pro\CLI\Concerns\InteractsWithPro;
use Foundry\Pro\DeepDiagnosticsBuilder;

final class DeepDoctorCommand extends Command
{
    use InteractsWithPro;

    #[\Override]
    public function matches(array $args): bool
    {
        return ($args[0] ?? null) === 'doctor' && in_array('--deep', $args, true);
    }

    #[\Override]
    public function run(array $args, CommandContext $context): array
    {
        $license = $this->requirePro('doctor --deep', ['deep_diagnostics']);

        $baseArgs = array_values(array_filter(
            $args,
            static fn (string $arg): bool => $arg !== '--deep',
        ));
        $baseContext = new CommandContext($context->paths()->root(), true);
        $base = (new DoctorCommand())->run($baseArgs, $baseContext);

        $payload = $base['payload'];
        if (!is_array($payload) || array_key_exists('error', $payload)) {
            return $base;
        }

        $featureFilter = trim((string) ($payload['feature_filter'] ?? ''));
        $compiler = $context->graphCompiler();
        $graph = $compiler->loadGraph() ?? $compiler->compile(new CompileOptions(
            feature: $featureFilter !== '' ? $featureFilter : null,
            emit: false,
            useCache: false,
        ))->graph;

        $payload['deep'] = true;
        $payload['pro'] = [
            'license' => $license,
            'deep_diagnostics' => (new DeepDiagnosticsBuilder())->build(
                $graph,
                $featureFilter !== '' ? $featureFilter : null,
            ),
        ];

        return [
            'status' => $base['status'],
            'message' => $context->expectsJson() ? null : $this->renderHumanReport($payload),
            'payload' => $context->expectsJson() ? $payload : null,
        ];
    }

    /**
     * @param array<string,mixed> $payload
     */
    private function renderHumanReport(array $payload): string
    {
        $summary = is_array($payload['diagnostics_summary'] ?? null)
            ? $payload['diagnostics_summary']
            : ['error' => 0, 'warning' => 0, 'info' => 0];
        $deep = is_array($payload['pro']['deep_diagnostics'] ?? null)
            ? $payload['pro']['deep_diagnostics']
            : [];
        $graph = is_array($deep['graph'] ?? null) ? $deep['graph'] : [];

        $headline = 'Foundry doctor completed with deep diagnostics.';
        if ((int) ($summary['error'] ?? 0) > 0) {
            $headline = 'Foundry doctor found issues during deep diagnostics.';
        } elseif ((int) ($summary['warning'] ?? 0) > 0) {
            $headline = 'Foundry doctor completed with warnings and deep diagnostics.';
        }

        $lines = [$headline];
        $lines[] = sprintf(
            'Summary: %d error(s), %d warning(s), %d info.',
            (int) ($summary['error'] ?? 0),
            (int) ($summary['warning'] ?? 0),
            (int) ($summary['info'] ?? 0),
        );
        $lines[] = sprintf(
            'Graph: %d node(s), %d edge(s).',
            (int) ($graph['node_count'] ?? 0),
            (int) ($graph['edge_count'] ?? 0),
        );

        $hotspots = array_values(array_filter(
            (array) ($deep['hotspots'] ?? []),
            static fn (mixed $row): bool => is_array($row),
        ));
        if ($hotspots !== []) {
            $lines[] = 'Top hotspots:';
            foreach (array_slice($hotspots, 0, 5) as $row) {
                $lines[] = sprintf(
                    '- %s (%s, %d connection(s))',
                    (string) ($row['label'] ?? $row['node_id'] ?? 'unknown'),
                    (string) ($row['type'] ?? 'node'),
                    (int) ($row['connections'] ?? 0),
                );
            }
        }

        return implode(PHP_EOL, $lines);
    }
}
