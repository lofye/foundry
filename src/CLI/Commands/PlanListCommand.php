<?php

declare(strict_types=1);

namespace Foundry\CLI\Commands;

use Foundry\CLI\Command;
use Foundry\CLI\CommandContext;
use Foundry\Generate\PlanRecordStore;

final class PlanListCommand extends Command
{
    #[\Override]
    public function supportedSignatures(): array
    {
        return ['plan:list'];
    }

    #[\Override]
    public function matches(array $args): bool
    {
        return ($args[0] ?? null) === 'plan:list';
    }

    #[\Override]
    public function run(array $args, CommandContext $context): array
    {
        $plans = (new PlanRecordStore($context->paths()))->list();

        return [
            'status' => 0,
            'message' => $context->expectsJson() ? null : $this->renderMessage($plans),
            'payload' => $context->expectsJson() ? ['plans' => $plans] : null,
        ];
    }

    /**
     * @param list<array<string,mixed>> $plans
     */
    private function renderMessage(array $plans): string
    {
        if ($plans === []) {
            return 'No persisted plans found.';
        }

        $lines = ['Persisted plans:'];
        foreach ($plans as $plan) {
            $lines[] = sprintf(
                '- %s | %s | %s | %s | %s',
                (string) ($plan['plan_id'] ?? ''),
                (string) ($plan['timestamp'] ?? ''),
                (string) ($plan['status'] ?? ''),
                (string) ($plan['mode'] ?? ''),
                (string) ($plan['intent'] ?? ''),
            );
        }

        return implode(PHP_EOL, $lines);
    }
}
