<?php

declare(strict_types=1);

namespace Foundry\CLI\Commands;

use Foundry\CLI\Command;
use Foundry\CLI\CommandContext;
use Foundry\Generate\PlanRecordStore;
use Foundry\Support\FoundryError;
use Foundry\Support\Json;

final class PlanShowCommand extends Command
{
    #[\Override]
    public function supportedSignatures(): array
    {
        return ['plan:show'];
    }

    #[\Override]
    public function matches(array $args): bool
    {
        return ($args[0] ?? null) === 'plan:show';
    }

    #[\Override]
    public function run(array $args, CommandContext $context): array
    {
        $planId = trim((string) ($args[1] ?? ''));
        if ($planId === '') {
            throw new FoundryError(
                'PLAN_SHOW_ID_REQUIRED',
                'validation',
                [],
                'Plan id required.',
            );
        }

        $record = (new PlanRecordStore($context->paths()))->load($planId);
        if (!is_array($record)) {
            throw new FoundryError(
                'PLAN_RECORD_NOT_FOUND',
                'not_found',
                ['plan_id' => $planId],
                'Persisted plan record not found.',
            );
        }

        return [
            'status' => 0,
            'message' => $context->expectsJson() ? null : $this->renderMessage($record),
            'payload' => $context->expectsJson() ? $record : null,
        ];
    }

    /**
     * @param array<string,mixed> $record
     */
    private function renderMessage(array $record): string
    {
        $lines = [
            'Plan: ' . (string) ($record['plan_id'] ?? ''),
            'Timestamp: ' . (string) ($record['timestamp'] ?? ''),
            'Status: ' . (string) ($record['status'] ?? ''),
            'Mode: ' . (string) ($record['mode'] ?? ''),
            'Intent: ' . (string) ($record['intent'] ?? ''),
            'Risk: ' . (string) ($record['risk_level'] ?? 'unknown'),
            'Storage path: ' . (string) ($record['storage_path'] ?? ''),
            'Affected files: ' . count((array) ($record['affected_files'] ?? [])),
            'Executed actions: ' . count((array) ($record['actions_executed'] ?? [])),
            '',
            'Stored record:',
            Json::encode($record, true),
        ];

        return implode(PHP_EOL, $lines);
    }
}
