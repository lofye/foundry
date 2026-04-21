<?php

declare(strict_types=1);

namespace Foundry\Context;

final class ContextDiagnosticOutputCoalescer
{
    /**
     * @param list<ContextDoctorDiagnosticRuleResult> $results
     * @return list<ContextDoctorDiagnosticRuleResult>
     */
    public function coalesceRuleResults(array $results): array
    {
        $coalesced = [];

        foreach ($results as $result) {
            $normalized = $this->normalizedRuleResult($result);
            $key = $this->ruleResultCoalescingKey($normalized);

            if (!isset($coalesced[$key])) {
                $coalesced[$key] = $normalized;
                continue;
            }

            $existing = $coalesced[$key];
            $coalesced[$key] = new ContextDoctorDiagnosticRuleResult(
                code: $existing->code,
                message: $existing->message,
                targets: $existing->targets,
                requiredActions: $this->coalesceRequiredActions(array_merge(
                    $existing->requiredActions,
                    $normalized->requiredActions,
                )),
                requiresRepair: $existing->requiresRepair || $normalized->requiresRepair,
            );
        }

        return array_values($coalesced);
    }

    /**
     * @param list<string> $actions
     * @return list<string>
     */
    public function coalesceRequiredActions(array $actions): array
    {
        $seen = [];
        $coalesced = [];

        foreach ($actions as $action) {
            $canonical = $this->normalizedActionKey((string) $action);
            if ($canonical === '' || isset($seen[$canonical])) {
                continue;
            }

            $seen[$canonical] = true;
            $coalesced[] = (string) $action;
        }

        return $coalesced;
    }

    /**
     * @param list<array<string,mixed>> $issues
     * @return list<array<string,mixed>>
     */
    public function coalesceIssueRows(array $issues): array
    {
        $seen = [];
        $coalesced = [];

        foreach ($issues as $issue) {
            $key = $this->issueRowKey($issue);
            if ($key === '' || isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $coalesced[] = $issue;
        }

        return $coalesced;
    }

    private function normalizedRuleResult(ContextDoctorDiagnosticRuleResult $result): ContextDoctorDiagnosticRuleResult
    {
        return new ContextDoctorDiagnosticRuleResult(
            code: $result->code,
            message: $result->message,
            targets: $this->normalizedTargets($result->targets),
            requiredActions: $this->coalesceRequiredActions($result->requiredActions),
            requiresRepair: $result->requiresRepair,
        );
    }

    /**
     * @param list<ContextDoctorDiagnosticTarget> $targets
     * @return list<ContextDoctorDiagnosticTarget>
     */
    private function normalizedTargets(array $targets): array
    {
        $seen = [];
        $normalized = [];

        foreach ($targets as $target) {
            $key = $this->diagnosticTargetKey($target);
            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $normalized[] = $target;
        }

        usort(
            $normalized,
            fn(ContextDoctorDiagnosticTarget $left, ContextDoctorDiagnosticTarget $right): int => $this->diagnosticTargetKey($left) <=> $this->diagnosticTargetKey($right),
        );

        return array_values($normalized);
    }

    private function ruleResultCoalescingKey(ContextDoctorDiagnosticRuleResult $result): string
    {
        $targets = implode('|', array_map(
            fn(ContextDoctorDiagnosticTarget $target): string => $this->diagnosticTargetKey($target),
            $result->targets,
        ));
        $actions = implode('|', array_map(
            fn(string $action): string => $this->normalizedActionKey($action),
            $result->requiredActions,
        ));

        return implode("\n", [
            $targets,
            trim($result->message),
            $actions,
        ]);
    }

    private function diagnosticTargetKey(ContextDoctorDiagnosticTarget $target): string
    {
        $bucketOrder = match ($target->bucket) {
            'spec' => '0',
            'state' => '1',
            'decisions' => '2',
            default => '9',
        };

        return $bucketOrder . ':' . $target->bucket . ':' . $target->filePath;
    }

    private function normalizedActionKey(string $action): string
    {
        return trim((string) (preg_replace('/\s+/', ' ', trim($action)) ?? trim($action)));
    }

    /**
     * @param array<string,mixed> $issue
     */
    private function issueRowKey(array $issue): string
    {
        $code = trim((string) ($issue['code'] ?? ''));
        if ($code === '') {
            return '';
        }

        return implode("\n", [
            trim((string) ($issue['source'] ?? '')),
            $code,
            trim((string) ($issue['file_path'] ?? '')),
            trim((string) ($issue['section'] ?? '')),
            trim((string) ($issue['message'] ?? '')),
        ]);
    }
}
