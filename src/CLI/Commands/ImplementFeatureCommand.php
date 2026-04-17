<?php

declare(strict_types=1);

namespace Foundry\CLI\Commands;

use Foundry\CLI\Command;
use Foundry\CLI\CommandContext;
use Foundry\Context\ContextExecutionService;
use Foundry\Support\FoundryError;

final class ImplementFeatureCommand extends Command
{
    #[\Override]
    public function supportedSignatures(): array
    {
        return ['implement feature'];
    }

    #[\Override]
    public function matches(array $args): bool
    {
        return ($args[0] ?? null) === 'implement' && ($args[1] ?? null) === 'feature';
    }

    #[\Override]
    public function run(array $args, CommandContext $context): array
    {
        $featureName = (string) ($args[2] ?? '');
        if ($featureName === '') {
            throw new FoundryError(
                'CLI_IMPLEMENT_FEATURE_REQUIRED',
                'validation',
                [],
                'Implement feature name required.',
            );
        }

        $repair = in_array('--repair', $args, true);
        $autoRepair = in_array('--auto-repair', $args, true);
        if ($repair && $autoRepair) {
            throw new FoundryError(
                'CLI_IMPLEMENT_REPAIR_MODE_CONFLICT',
                'validation',
                ['repair' => true, 'auto_repair' => true],
                'Use either --repair or --auto-repair, not both.',
            );
        }

        $payload = (new ContextExecutionService($context->paths()))
            ->execute($featureName, repair: $repair, autoRepair: $autoRepair)
            ->toArray();
        $status = (string) ($payload['status'] ?? 'blocked');

        return [
            'status' => in_array($status, ['completed', 'repaired'], true) ? 0 : 1,
            'message' => $context->expectsJson() ? null : $this->renderMessage($payload),
            'payload' => $context->expectsJson() ? $payload : null,
        ];
    }

    /**
     * @param array{
     *     feature:string,
     *     status:string,
     *     can_proceed:bool,
     *     requires_repair:bool,
     *     repair_attempted:bool,
     *     repair_successful:bool,
     *     actions_taken:list<string>,
     *     issues:list<array<string,mixed>>,
     *     required_actions:list<string>
     * } $payload
     */
    private function renderMessage(array $payload): string
    {
        $lines = [
            'Implement feature: ' . $payload['feature'],
            'Status: ' . $payload['status'],
            'Can proceed: ' . ($payload['can_proceed'] ? 'yes' : 'no'),
            'Requires repair: ' . ($payload['requires_repair'] ? 'yes' : 'no'),
            'Repair attempted: ' . ($payload['repair_attempted'] ? 'yes' : 'no'),
            'Repair successful: ' . ($payload['repair_successful'] ? 'yes' : 'no'),
            'Actions taken:',
        ];

        if ($payload['actions_taken'] === []) {
            $lines[] = '- none';
        } else {
            foreach ($payload['actions_taken'] as $action) {
                $lines[] = '- ' . $action;
            }
        }

        $lines[] = 'Issues:';
        if ($payload['issues'] === []) {
            $lines[] = '- none';
        } else {
            foreach ($payload['issues'] as $issue) {
                $lines[] = '- ' . (string) ($issue['code'] ?? '') . ': ' . (string) ($issue['message'] ?? '');
            }
        }

        $lines[] = 'Required actions:';
        if ($payload['required_actions'] === []) {
            $lines[] = '- none';
        } else {
            foreach ($payload['required_actions'] as $action) {
                $lines[] = '- ' . $action;
            }
        }

        if (is_string($payload['reason'] ?? null) && $payload['reason'] !== '') {
            $lines[] = 'Reason: ' . $payload['reason'];
        }

        if (is_string($payload['required_action'] ?? null) && $payload['required_action'] !== '') {
            $lines[] = 'Required action: ' . $payload['required_action'];
        }

        return implode(PHP_EOL, $lines);
    }
}
