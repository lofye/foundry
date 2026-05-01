<?php

declare(strict_types=1);

namespace Foundry\CLI\Commands;

use Foundry\CLI\Command;
use Foundry\CLI\CommandContext;
use Foundry\Generate\GenerateMetricsStore;
use Foundry\Support\FoundryError;

final class GenerateMetricsCommand extends Command
{
    #[\Override]
    public function supportedSignatures(): array
    {
        return ['generate:metrics'];
    }

    #[\Override]
    public function matches(array $args): bool
    {
        return ($args[0] ?? null) === 'generate:metrics';
    }

    #[\Override]
    public function run(array $args, CommandContext $context): array
    {
        $raw = in_array('--raw', $args, true);
        $store = new GenerateMetricsStore($context->paths());
        $issues = $store->verify();
        if ($issues !== []) {
            throw new FoundryError(
                'GENERATE_METRICS_INVALID',
                'validation',
                ['issues' => $issues],
                'Generate metrics records are invalid.',
            );
        }

        $payload = [
            'metrics_enabled' => $store->enabled(),
            'aggregates' => $store->aggregates(),
            'records' => $raw ? $store->list() : null,
        ];

        return [
            'status' => 0,
            'message' => $context->expectsJson() ? null : $this->renderMessage($payload),
            'payload' => $context->expectsJson() ? $payload['aggregates'] : null,
        ];
    }

    /**
     * @param array<string,mixed> $payload
     */
    private function renderMessage(array $payload): string
    {
        $aggregates = is_array($payload['aggregates'] ?? null) ? $payload['aggregates'] : [];
        $templates = is_array($aggregates['templates'] ?? null) ? $aggregates['templates'] : [];
        $approvals = is_array($aggregates['approvals'] ?? null) ? $aggregates['approvals'] : [];

        $lines = [
            'total runs: ' . (int) ($aggregates['total_runs'] ?? 0),
            'failures: ' . (int) ($aggregates['failures'] ?? 0),
            'failure rate: ' . (float) ($aggregates['failure_rate'] ?? 0) . '%',
            'average workflow steps: ' . (float) ($aggregates['average_steps'] ?? 0),
            'template usage:',
        ];

        if ($templates === []) {
            $lines[] = '  none: 0';
        } else {
            foreach ($templates as $templateId => $count) {
                $lines[] = '  ' . (string) $templateId . ': ' . (int) $count;
            }
        }

        $lines[] = 'approval usage:';
        $lines[] = '  required: ' . (int) ($approvals['required'] ?? 0);
        $lines[] = 'policy violations: ' . (int) ($aggregates['policy_violations'] ?? 0);

        return implode(PHP_EOL, $lines);
    }
}
