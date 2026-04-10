<?php

declare(strict_types=1);

namespace Foundry\CLI\Commands;

use Foundry\CLI\Command;
use Foundry\CLI\CommandContext;
use Foundry\Context\ContextPlanningService;
use Foundry\Support\FoundryError;

final class PlanFeatureCommand extends Command
{
    #[\Override]
    public function supportedSignatures(): array
    {
        return ['plan feature'];
    }

    #[\Override]
    public function matches(array $args): bool
    {
        return ($args[0] ?? null) === 'plan' && ($args[1] ?? null) === 'feature';
    }

    #[\Override]
    public function run(array $args, CommandContext $context): array
    {
        $featureName = (string) ($args[2] ?? '');
        if ($featureName === '') {
            throw new FoundryError(
                'CLI_PLAN_FEATURE_REQUIRED',
                'validation',
                [],
                'Plan feature name required.',
            );
        }

        $payload = (new ContextPlanningService($context->paths()))
            ->plan($featureName)
            ->toArray();

        return [
            'status' => $payload['status'] === 'planned' ? 0 : 1,
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
     *     spec_id:string|null,
     *     spec_path:string|null,
     *     actions_taken:list<string>,
     *     issues:list<array<string,mixed>>,
     *     required_actions:list<string>
     * } $payload
     */
    private function renderMessage(array $payload): string
    {
        $lines = [
            'Plan feature: ' . $payload['feature'],
            'Status: ' . $payload['status'],
            'Can proceed: ' . ($payload['can_proceed'] ? 'yes' : 'no'),
            'Requires repair: ' . ($payload['requires_repair'] ? 'yes' : 'no'),
            'Spec id: ' . ($payload['spec_id'] ?? 'none'),
            'Spec path: ' . ($payload['spec_path'] ?? 'none'),
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

        return implode(PHP_EOL, $lines);
    }
}
