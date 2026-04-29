<?php

declare(strict_types=1);

namespace Foundry\Generate;

use Foundry\Support\FoundryError;
use Foundry\Support\Json;
use Foundry\Support\Paths;

final class ApprovalRecordStore
{
    /**
     * @param null|\Closure():\DateTimeImmutable $clock
     */
    public function __construct(
        private readonly Paths $paths,
        private readonly ?\Closure $clock = null,
    ) {}

    /**
     * @return array<string,mixed>|null
     */
    public function load(string $planId): ?array
    {
        $path = $this->recordPath($planId);
        if (!is_file($path)) {
            return null;
        }

        $json = file_get_contents($path);
        if (!is_string($json) || trim($json) === '') {
            throw new FoundryError(
                'PLAN_APPROVAL_RECORD_UNREADABLE',
                'filesystem',
                ['plan_id' => $planId],
                'Plan approval record is unreadable.',
            );
        }

        $record = Json::decodeAssoc($json);
        $this->validate($record, $planId);

        return $record;
    }

    /**
     * @return array<string,mixed>
     */
    public function ensure(string $planId, bool $required, int $minApprovals): array
    {
        $existing = $this->load($planId);
        if (is_array($existing)) {
            return $existing;
        }

        if ($minApprovals < 1) {
            throw new FoundryError(
                'PLAN_APPROVAL_MIN_INVALID',
                'validation',
                ['plan_id' => $planId, 'min_approvals' => $minApprovals],
                'Plan approval min_approvals must be at least 1.',
            );
        }

        $record = [
            'schema' => 'foundry.generate.approval_record.v1',
            'plan_id' => $planId,
            'status' => 'pending',
            'required' => $required,
            'min_approvals' => $minApprovals,
            'approvals' => [],
        ];
        $this->write($planId, $record);

        return $record;
    }

    /**
     * @return array<string,mixed>
     */
    public function append(string $planId, string $user, string $action, ?string $comment = null): array
    {
        $record = $this->load($planId);
        if (!is_array($record)) {
            throw new FoundryError(
                'PLAN_APPROVAL_RECORD_NOT_FOUND',
                'not_found',
                ['plan_id' => $planId],
                'Plan approval record not found.',
            );
        }

        if (!in_array($action, ['approve', 'reject', 'comment'], true)) {
            throw new FoundryError(
                'PLAN_APPROVAL_ACTION_INVALID',
                'validation',
                ['plan_id' => $planId, 'action' => $action],
                'Plan approval actions must be approve, reject, or comment.',
            );
        }

        $user = trim($user);
        if ($user === '') {
            throw new FoundryError(
                'PLAN_APPROVAL_USER_REQUIRED',
                'validation',
                ['plan_id' => $planId],
                'Plan approval actions require --user.',
            );
        }

        $approvals = array_values(array_filter((array) ($record['approvals'] ?? []), 'is_array'));
        $digest = $this->chainDigest($approvals);
        $entry = [
            'user' => $user,
            'action' => $action,
            'timestamp' => $this->now()->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z'),
            'comment' => $comment !== null && trim($comment) !== '' ? trim($comment) : null,
            'entry_index' => count($approvals),
            'previous_digest' => $digest,
        ];
        $entry['entry_digest'] = $this->entryDigest($entry);
        $approvals[] = $entry;

        $record['approvals'] = $approvals;
        $record['status'] = $this->resolveStatus($record);
        $this->write($planId, $record);

        return $record;
    }

    /**
     * @param array<string,mixed> $record
     */
    public function validate(array $record, ?string $expectedPlanId = null): void
    {
        if ((string) ($record['schema'] ?? '') !== 'foundry.generate.approval_record.v1') {
            throw new FoundryError(
                'PLAN_APPROVAL_SCHEMA_INVALID',
                'validation',
                ['plan_id' => (string) ($record['plan_id'] ?? '')],
                'Plan approval record must use schema foundry.generate.approval_record.v1.',
            );
        }

        $planId = trim((string) ($record['plan_id'] ?? ''));
        if ($planId === '' || ($expectedPlanId !== null && $planId !== $expectedPlanId)) {
            throw new FoundryError(
                'PLAN_APPROVAL_PLAN_ID_INVALID',
                'validation',
                ['plan_id' => $planId, 'expected_plan_id' => $expectedPlanId],
                'Plan approval record plan_id is invalid.',
            );
        }

        $required = ($record['required'] ?? false) === true;
        $minApprovals = (int) ($record['min_approvals'] ?? 0);
        if ($required && $minApprovals < 1) {
            throw new FoundryError(
                'PLAN_APPROVAL_MIN_INVALID',
                'validation',
                ['plan_id' => $planId, 'min_approvals' => $minApprovals],
                'Plan approval min_approvals must be at least 1.',
            );
        }

        $approvals = array_values(array_filter((array) ($record['approvals'] ?? []), 'is_array'));
        $digest = null;
        foreach ($approvals as $index => $entry) {
            $user = trim((string) ($entry['user'] ?? ''));
            $action = (string) ($entry['action'] ?? '');
            if ($user === '' || !in_array($action, ['approve', 'reject', 'comment'], true)) {
                throw new FoundryError(
                    'PLAN_APPROVAL_ENTRY_INVALID',
                    'validation',
                    ['plan_id' => $planId, 'entry_index' => $index],
                    'Plan approval entries must include a user and a valid action.',
                );
            }

            if (($entry['entry_index'] ?? null) !== $index) {
                throw new FoundryError(
                    'PLAN_APPROVAL_NON_APPEND_ONLY',
                    'validation',
                    ['plan_id' => $planId, 'entry_index' => $index],
                    'Plan approval record failed append-only validation.',
                );
            }

            $previousDigest = $entry['previous_digest'] ?? null;
            if ($previousDigest !== $digest) {
                throw new FoundryError(
                    'PLAN_APPROVAL_NON_APPEND_ONLY',
                    'validation',
                    ['plan_id' => $planId, 'entry_index' => $index],
                    'Plan approval record failed append-only validation.',
                );
            }

            $expectedDigest = $this->entryDigest($entry);
            if (($entry['entry_digest'] ?? null) !== $expectedDigest) {
                throw new FoundryError(
                    'PLAN_APPROVAL_NON_APPEND_ONLY',
                    'validation',
                    ['plan_id' => $planId, 'entry_index' => $index],
                    'Plan approval record failed append-only validation.',
                );
            }

            $digest = $expectedDigest;
        }

        $expectedStatus = $this->resolveStatus($record);
        if (($record['status'] ?? null) !== $expectedStatus) {
            throw new FoundryError(
                'PLAN_APPROVAL_STATUS_INVALID',
                'validation',
                ['plan_id' => $planId, 'status' => $record['status'] ?? null, 'expected' => $expectedStatus],
                'Plan approval record status does not match approvals.',
            );
        }
    }

    /**
     * @param array<string,mixed> $record
     */
    private function resolveStatus(array $record): string
    {
        $required = ($record['required'] ?? false) === true;
        if (!$required) {
            return 'approved';
        }

        $minApprovals = max(1, (int) ($record['min_approvals'] ?? 1));
        $approvals = array_values(array_filter((array) ($record['approvals'] ?? []), 'is_array'));
        $approveCount = 0;

        foreach ($approvals as $entry) {
            $action = (string) ($entry['action'] ?? '');
            if ($action === 'reject') {
                return 'rejected';
            }
            if ($action === 'approve') {
                $approveCount++;
            }
        }

        if ($approveCount >= $minApprovals) {
            return 'approved';
        }

        return 'pending';
    }

    /**
     * @param list<array<string,mixed>> $approvals
     */
    private function chainDigest(array $approvals): ?string
    {
        if ($approvals === []) {
            return null;
        }

        $last = $approvals[array_key_last($approvals)];

        return is_array($last) ? (($last['entry_digest'] ?? null) !== null ? (string) $last['entry_digest'] : null) : null;
    }

    /**
     * @param array<string,mixed> $entry
     */
    private function entryDigest(array $entry): string
    {
        $payload = [
            'user' => (string) ($entry['user'] ?? ''),
            'action' => (string) ($entry['action'] ?? ''),
            'timestamp' => $entry['timestamp'] ?? null,
            'comment' => $entry['comment'] ?? null,
            'entry_index' => $entry['entry_index'] ?? null,
            'previous_digest' => $entry['previous_digest'] ?? null,
        ];

        return hash('sha256', Json::encode($payload));
    }

    /**
     * @param array<string,mixed> $record
     */
    private function write(string $planId, array $record): void
    {
        $this->ensureDirectory();
        $this->validate($record, $planId);
        $path = $this->recordPath($planId);
        if (file_put_contents($path, Json::encode($record, true) . PHP_EOL) === false) {
            throw new FoundryError(
                'PLAN_APPROVAL_RECORD_WRITE_FAILED',
                'filesystem',
                ['plan_id' => $planId],
                'Unable to write plan approval record.',
            );
        }
    }

    private function ensureDirectory(): void
    {
        $dir = $this->paths->join('.foundry/plans/approvals');
        if (is_dir($dir)) {
            return;
        }

        if (!mkdir($dir, 0777, true) && !is_dir($dir)) {
            throw new FoundryError(
                'PLAN_APPROVAL_DIRECTORY_CREATE_FAILED',
                'filesystem',
                [],
                'Unable to create the plan approvals directory.',
            );
        }
    }

    private function recordPath(string $planId): string
    {
        return $this->paths->join('.foundry/plans/approvals/' . $planId . '.json');
    }

    private function now(): \DateTimeImmutable
    {
        if ($this->clock instanceof \Closure) {
            return ($this->clock)();
        }

        return new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }
}
