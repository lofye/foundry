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
        $workflowLinkage = is_array($record['metadata']['workflow'] ?? null) ? $record['metadata']['workflow'] : null;
        $template = is_array($record['metadata']['template'] ?? null) ? $record['metadata']['template'] : null;
        $isWorkflowRecord = (string) ($record['schema'] ?? '') === 'foundry.generate.workflow_record.v1';

        $lines = ['Plan: ' . (string) ($record['plan_id'] ?? '')];
        if ($isWorkflowRecord) {
            $lines[] = 'Record kind: workflow';
            $lines[] = 'Workflow: ' . (string) ($record['workflow_id'] ?? '');
            $lines[] = 'Source: ' . (string) ($record['source']['path'] ?? '');
        } elseif ($workflowLinkage !== null) {
            $lines[] = 'Record kind: workflow_step';
            $lines[] = sprintf(
                'Workflow step: %s @ %s (%s)',
                (string) ($workflowLinkage['step_id'] ?? ''),
                (string) ($workflowLinkage['step_index'] ?? ''),
                (string) ($workflowLinkage['workflow_id'] ?? ''),
            );
        } else {
            $lines[] = 'Record kind: generate';
        }

        $lines[] = 'Timestamp: ' . (string) ($record['timestamp'] ?? '');
        $lines[] = 'Status: ' . (string) ($record['status'] ?? '');
        $lines[] = 'Mode: ' . (string) ($record['mode'] ?? '');
        $lines[] = 'Intent: ' . (string) ($record['intent'] ?? '');
        $lines[] = 'Risk: ' . (string) ($record['risk_level'] ?? 'unknown');
        $lines[] = 'Storage path: ' . (string) ($record['storage_path'] ?? '');
        $lines[] = 'Affected files: ' . count((array) ($record['affected_files'] ?? []));
        $lines[] = 'Executed actions: ' . count((array) ($record['actions_executed'] ?? []));
        if ($template !== null) {
            $lines[] = 'Template: ' . (string) ($template['template_id'] ?? '');
            $lines[] = 'Template file: ' . (string) ($template['path'] ?? '');
            $lines[] = 'Template params: ' . Json::encode($template['resolved_parameters'] ?? []);
        }

        if ($isWorkflowRecord) {
            $lines[] = '';
            $lines[] = sprintf(
                'Workflow result: completed=%d failed_step=%s skipped=%d',
                (int) ($record['result']['completed_steps'] ?? 0),
                (string) (($record['result']['failed_step'] ?? null) ?? 'none'),
                (int) ($record['result']['skipped_steps'] ?? 0),
            );
            $lines[] = 'Workflow steps:';
            foreach (array_values(array_filter((array) ($record['steps'] ?? []), 'is_array')) as $step) {
                $recordId = trim((string) ($step['record_id'] ?? ''));
                $suffix = $recordId !== '' ? ' ' . $recordId : '';
                $lines[] = sprintf(
                    '  step %s %s %s%s',
                    (string) ($step['index'] ?? '?'),
                    (string) ($step['step_id'] ?? 'step'),
                    (string) ($step['status'] ?? 'unknown'),
                    $suffix,
                );
            }

            $rollbackGuidance = array_values(array_filter(array_map('strval', (array) ($record['rollback_guidance'] ?? []))));
            if ($rollbackGuidance !== []) {
                $lines[] = 'Rollback guidance:';
                foreach ($rollbackGuidance as $guidance) {
                    $lines[] = '  ' . $guidance;
                }
            }
        }

        $lines[] = '';
        $lines[] = 'Stored record:';
        $lines[] = Json::encode($record, true);

        return implode(PHP_EOL, $lines);
    }
}
