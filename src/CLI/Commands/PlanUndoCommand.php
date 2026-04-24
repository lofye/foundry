<?php

declare(strict_types=1);

namespace Foundry\CLI\Commands;

use Foundry\CLI\Command;
use Foundry\CLI\CommandContext;
use Foundry\Generate\GenerateEngine;
use Foundry\Support\FoundryError;

final class PlanUndoCommand extends Command
{
    #[\Override]
    public function supportedSignatures(): array
    {
        return ['plan:undo'];
    }

    #[\Override]
    public function matches(array $args): bool
    {
        return ($args[0] ?? null) === 'plan:undo';
    }

    #[\Override]
    public function run(array $args, CommandContext $context): array
    {
        $planId = trim((string) ($args[1] ?? ''));
        if ($planId === '') {
            throw new FoundryError(
                'PLAN_UNDO_ID_REQUIRED',
                'validation',
                [],
                'Plan id required.',
            );
        }

        $payload = (new GenerateEngine(
            $context->paths(),
            apiSurfaceRegistry: $context->apiSurfaceRegistry(),
        ))->undo(
            $planId,
            in_array('--dry-run', $args, true),
            in_array('--yes', $args, true),
        );
        $status = ($payload['status'] ?? null) === 'confirmation_required' ? 1 : 0;

        return [
            'status' => $status,
            'message' => $context->expectsJson() ? null : $this->renderMessage($payload),
            'payload' => $context->expectsJson() ? $payload : null,
        ];
    }

    /**
     * @param array<string,mixed> $payload
     */
    private function renderMessage(array $payload): string
    {
        $status = (string) ($payload['status'] ?? 'unknown');
        $headline = match ($status) {
            'dry_run' => 'Undo dry run prepared.',
            'confirmation_required' => 'Undo confirmation required.',
            'undone' => 'Undo completed.',
            'partial' => 'Undo completed with warnings.',
            'nothing_to_undo' => 'No applied generate changes to undo.',
            default => 'Undo finished.',
        };

        $lines = [
            $headline,
            'Plan: ' . (string) ($payload['plan_id'] ?? ''),
            'Status: ' . $status,
            'Rollback mode: ' . (string) ($payload['rollback_mode'] ?? 'snapshot'),
            'Fully reversible: ' . (((bool) ($payload['fully_reversible'] ?? false)) ? 'yes' : 'no'),
            'Reversible: ' . (((bool) ($payload['reversible'] ?? false)) ? 'yes' : 'no'),
            'Requires confirmation: ' . (((bool) ($payload['requires_confirmation'] ?? false)) ? 'yes' : 'no'),
            'Confidence: ' . (string) ($payload['confidence_level'] ?? 'unknown'),
            'Reversible actions: ' . count((array) ($payload['reversible_actions'] ?? [])),
            'Irreversible actions: ' . count((array) ($payload['irreversible_actions'] ?? [])),
            'Skipped actions: ' . count((array) ($payload['skipped_actions'] ?? [])),
            'Files recovered: ' . count((array) ($payload['files_recovered'] ?? [])),
            'Files unrecoverable: ' . count((array) ($payload['files_unrecoverable'] ?? [])),
        ];

        $source = is_array($payload['source_record'] ?? null) ? $payload['source_record'] : [];
        if ($source !== []) {
            $lines[] = 'Storage path: ' . (string) ($source['storage_path'] ?? '');
        }

        $warnings = array_values(array_filter(array_map('strval', (array) ($payload['warnings'] ?? []))));
        if ($warnings !== []) {
            $lines[] = '';
            $lines[] = 'Warnings:';
            foreach ($warnings as $warning) {
                $lines[] = '- ' . $warning;
            }
        }

        $integrityWarnings = is_array($payload['integrity_warnings'] ?? null) ? $payload['integrity_warnings'] : [];
        if ($integrityWarnings !== []) {
            $lines[] = '';
            $lines[] = 'Integrity warnings:';
            foreach ($integrityWarnings as $warning) {
                if (!is_array($warning)) {
                    continue;
                }

                $lines[] = '- ' . (string) ($warning['path'] ?? '') . ': ' . (string) ($warning['message'] ?? 'Integrity mismatch detected.');
            }
        }

        return implode(PHP_EOL, $lines);
    }
}
