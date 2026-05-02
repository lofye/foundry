<?php

declare(strict_types=1);

namespace Foundry\CLI\Commands;

use Foundry\CLI\Command;
use Foundry\CLI\CommandContext;
use Foundry\Context\ExecutionSpecPlanService;

final class SpecPlanCommand extends Command
{
    #[\Override]
    public function supportedSignatures(): array
    {
        return ['spec:plan'];
    }

    #[\Override]
    public function matches(array $args): bool
    {
        return ($args[0] ?? null) === 'spec:plan';
    }

    #[\Override]
    public function run(array $args, CommandContext $context): array
    {
        $feature = (string) ($args[1] ?? '');
        $id = (string) ($args[2] ?? '');
        $force = in_array('--force', $args, true);

        $payload = (new ExecutionSpecPlanService($context->paths()))
            ->createPlan($feature, $id, $force);

        $ok = ($payload['status'] ?? '') === 'created';

        return [
            'status' => $ok ? 0 : 1,
            'message' => $context->expectsJson() ? null : $this->renderMessage($payload),
            'payload' => $context->expectsJson() ? $payload : null,
        ];
    }

    /**
     * @param array<string,mixed> $payload
     */
    private function renderMessage(array $payload): string
    {
        if (($payload['status'] ?? '') === 'created') {
            return implode(PHP_EOL, [
                'Created implementation plan',
                '',
                'Feature: ' . (string) ($payload['feature'] ?? ''),
                'Spec: ' . (string) ($payload['spec'] ?? ''),
                'Plan: ' . (string) ($payload['plan'] ?? ''),
            ]);
        }

        return implode(PHP_EOL, [
            'Could not create implementation plan',
            '',
            'Reason: ' . (string) ($payload['error'] ?? 'unknown_error'),
            'Feature: ' . (string) ($payload['feature'] ?? ''),
            'Spec: ' . (string) ($payload['spec'] ?? ''),
            'Plan: ' . (string) ($payload['plan'] ?? ''),
        ]);
    }
}
