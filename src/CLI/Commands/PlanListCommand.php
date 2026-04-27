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
            $recordKind = (string) ($plan['record_kind'] ?? 'generate');
            $workflowBits = [];
            if (is_string($plan['workflow_id'] ?? null) && (string) ($plan['workflow_id'] ?? '') !== '') {
                $workflowBits[] = 'workflow=' . (string) $plan['workflow_id'];
            }
            if (is_string($plan['workflow_step_id'] ?? null) && (string) ($plan['workflow_step_id'] ?? '') !== '') {
                $workflowBits[] = sprintf(
                    'step=%s@%s',
                    (string) $plan['workflow_step_id'],
                    (string) ($plan['workflow_step_index'] ?? '?'),
                );
            }
            if (is_string($plan['template_id'] ?? null) && (string) ($plan['template_id'] ?? '') !== '') {
                $workflowBits[] = 'template=' . (string) $plan['template_id'];
            }

            $suffix = $workflowBits !== [] ? ' | ' . implode(' | ', $workflowBits) : '';
            $lines[] = sprintf(
                '- %s | %s | %s | %s | %s | %s%s',
                (string) ($plan['plan_id'] ?? ''),
                (string) ($plan['timestamp'] ?? ''),
                (string) ($plan['status'] ?? ''),
                (string) ($plan['mode'] ?? ''),
                $recordKind,
                (string) ($plan['intent'] ?? ''),
                $suffix,
            );
        }

        return implode(PHP_EOL, $lines);
    }
}
