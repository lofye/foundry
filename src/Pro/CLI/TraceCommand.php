<?php
declare(strict_types=1);

namespace Foundry\Pro\CLI;

use Foundry\CLI\Command;
use Foundry\CLI\CommandContext;
use Foundry\Pro\CLI\Concerns\InteractsWithPro;
use Foundry\Pro\TraceAnalyzer;

final class TraceCommand extends Command
{
    use InteractsWithPro;

    #[\Override]
    public function matches(array $args): bool
    {
        return ($args[0] ?? null) === 'trace';
    }

    #[\Override]
    public function run(array $args, CommandContext $context): array
    {
        $license = $this->requirePro('trace', ['trace_analysis']);
        $target = trim(implode(' ', array_slice($args, 1)));

        $payload = (new TraceAnalyzer())->analyze(
            $context->paths()->join('storage/logs/trace.log'),
            $target !== '' ? $target : null,
        );
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
        if (($payload['found'] ?? false) !== true) {
            return 'No trace log found.';
        }

        return sprintf(
            'Trace analysis loaded %d matching event(s) from %d total event(s).',
            (int) ($payload['matched_events'] ?? 0),
            (int) ($payload['total_events'] ?? 0),
        );
    }
}
