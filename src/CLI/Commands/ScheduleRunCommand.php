<?php
declare(strict_types=1);

namespace Foundry\CLI\Commands;

use Foundry\CLI\Command;
use Foundry\CLI\CommandContext;

final class ScheduleRunCommand extends Command
{
    #[\Override]
    public function matches(array $args): bool
    {
        return in_array(($args[0] ?? ''), ['schedule:run', 'trace:tail'], true);
    }

    #[\Override]
    public function run(array $args, CommandContext $context): array
    {
        $command = (string) ($args[0] ?? '');

        if ($command === 'trace:tail') {
            $traceFile = $context->paths()->join('storage/logs/trace.log');
            if (!is_file($traceFile)) {
                return [
                    'status' => 0,
                    'message' => 'No trace log found.',
                    'payload' => ['events' => []],
                ];
            }

            $lines = file($traceFile, FILE_IGNORE_NEW_LINES) ?: [];
            $tail = array_slice($lines, -50);

            return [
                'status' => 0,
                'message' => 'Trace tail loaded.',
                'payload' => ['events' => $tail],
            ];
        }

        $schedulerIndexPath = $context->paths()->join('app/generated/scheduler_index.php');
        /** @var array<string,mixed> $tasks */
        $tasks = is_file($schedulerIndexPath) ? (array) (require $schedulerIndexPath) : [];

        return [
            'status' => 0,
            'message' => 'Schedule run completed.',
            'payload' => [
                'scheduled_tasks' => count($tasks),
                'ran' => 0,
            ],
        ];
    }
}
