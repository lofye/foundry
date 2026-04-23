<?php

declare(strict_types=1);

namespace Foundry\CLI\Commands;

use Foundry\CLI\Command;
use Foundry\CLI\CommandContext;
use Foundry\Generate\GenerateEngine;
use Foundry\Support\FoundryError;

final class PlanReplayCommand extends Command
{
    #[\Override]
    public function supportedSignatures(): array
    {
        return ['plan:replay'];
    }

    #[\Override]
    public function matches(array $args): bool
    {
        return ($args[0] ?? null) === 'plan:replay';
    }

    #[\Override]
    public function run(array $args, CommandContext $context): array
    {
        $planId = trim((string) ($args[1] ?? ''));
        if ($planId === '') {
            throw new FoundryError(
                'PLAN_REPLAY_ID_REQUIRED',
                'validation',
                [],
                'Plan id required.',
            );
        }

        $payload = (new GenerateEngine(
            $context->paths(),
            apiSurfaceRegistry: $context->apiSurfaceRegistry(),
        ))->replay(
            $planId,
            in_array('--strict', $args, true),
            in_array('--dry-run', $args, true),
        );

        return [
            'status' => 0,
            'message' => $context->expectsJson() ? null : $this->renderMessage($payload),
            'payload' => $context->expectsJson() ? $payload : null,
        ];
    }

    /**
     * @param array<string,mixed> $payload
     */
    private function renderMessage(array $payload): string
    {
        $lines = [
            (($payload['dry_run'] ?? false) === true ? 'Replay dry run prepared.' : 'Replay completed.'),
            'Plan: ' . (string) ($payload['plan_id'] ?? ''),
            'Replay mode: ' . (string) ($payload['replay_mode'] ?? 'adaptive'),
            'Replayability: ' . (((bool) ($payload['replayable'] ?? false)) ? 'ready' : 'blocked'),
            'Drift detected: ' . (((bool) ($payload['drift_detected'] ?? false)) ? 'yes' : 'no'),
            'Execution result: ' . (string) ($payload['status'] ?? 'unknown'),
            'Verification: ' . $this->renderVerification($payload['verification'] ?? null),
        ];

        $source = is_array($payload['source_record'] ?? null) ? $payload['source_record'] : [];
        if ($source !== []) {
            $lines[] = 'Replay source: ' . (string) ($source['selected_plan'] ?? 'original');
            $lines[] = 'Storage path: ' . (string) ($source['storage_path'] ?? '');
        }

        $driftSummary = is_array($payload['drift_summary'] ?? null) ? $payload['drift_summary'] : [];
        $messages = array_values(array_filter(array_map('strval', (array) ($driftSummary['messages'] ?? []))));
        if ($messages !== []) {
            $lines[] = '';
            $lines[] = 'Drift notices:';
            foreach ($messages as $message) {
                $lines[] = '- ' . $message;
            }
        }

        return implode(PHP_EOL, $lines);
    }

    private function renderVerification(mixed $verification): string
    {
        if (!is_array($verification)) {
            return 'unknown';
        }

        if (($verification['skipped'] ?? false) === true) {
            return 'skipped';
        }

        return ($verification['ok'] ?? false) === true ? 'passed' : 'failed';
    }
}
