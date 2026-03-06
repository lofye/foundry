<?php
declare(strict_types=1);

namespace Foundry\CLI\Commands;

use Foundry\CLI\Command;
use Foundry\CLI\CommandContext;

final class QueueWorkCommand extends Command
{
    #[\Override]
    public function matches(array $args): bool
    {
        return in_array(($args[0] ?? ''), ['queue:work', 'queue:inspect'], true);
    }

    #[\Override]
    public function run(array $args, CommandContext $context): array
    {
        $command = (string) ($args[0] ?? '');

        if ($command === 'queue:inspect') {
            $jobIndexPath = $context->paths()->join('app/generated/job_index.php');
            /** @var array<string,mixed> $jobs */
            $jobs = is_file($jobIndexPath) ? (array) (require $jobIndexPath) : [];

            return [
                'status' => 0,
                'message' => 'Queue inspected.',
                'payload' => [
                    'jobs' => $jobs,
                    'count' => count($jobs),
                ],
            ];
        }

        return [
            'status' => 0,
            'message' => 'Queue worker run completed.',
            'payload' => [
                'processed' => 0,
            ],
        ];
    }
}
