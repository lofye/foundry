<?php

declare(strict_types=1);

namespace Foundry\CLI\Commands;

use Foundry\CLI\Command;
use Foundry\CLI\CommandContext;
use Foundry\Generate\GenerateEngine;
use Foundry\Generate\InteractiveGenerateReviewer;
use Foundry\Generate\Intent;
use Foundry\Generate\TerminalInteractiveGenerateReviewer;
use Foundry\Packs\PackManager;
use Foundry\Support\FoundryError;

final class GenerateCommand extends Command
{
    /**
     * @var array<int,string>
     */
    private const RESERVED_TARGETS = [
        'feature',
        'starter',
        'resource',
        'admin-resource',
        'uploads',
        'notification',
        'api-resource',
        'docs',
        'indexes',
        'tests',
        'migration',
        'context',
        'billing',
        'workflow',
        'orchestration',
        'search-index',
        'stream',
        'locale',
        'roles',
        'policy',
        'inspect-ui',
    ];

    /**
     * @param null|\Closure(CommandContext):InteractiveGenerateReviewer $interactiveReviewerFactory
     */
    public function __construct(
        private readonly ?PackManager $packManager = null,
        private readonly ?\Closure $interactiveReviewerFactory = null,
    ) {}

    #[\Override]
    public function supportedSignatures(): array
    {
        return ['generate <intent>'];
    }

    #[\Override]
    public function matches(array $args): bool
    {
        if (($args[0] ?? null) !== 'generate') {
            return false;
        }

        if ($this->hasWorkflowFlag($args)) {
            return true;
        }

        $target = trim((string) ($args[1] ?? ''));
        if ($target === '' || str_starts_with($target, '--')) {
            return false;
        }

        return !in_array($target, self::RESERVED_TARGETS, true);
    }

    #[\Override]
    public function run(array $args, CommandContext $context): array
    {
        $intent = $this->parse($args);
        $payload = (new GenerateEngine(
            $context->paths(),
            $this->packManager,
            apiSurfaceRegistry: $context->apiSurfaceRegistry(),
            interactiveReviewer: $intent->interactive ? $this->interactiveReviewer($context) : null,
        ))->run($intent);

        return [
            'status' => ($payload['ok'] ?? true) === true ? 0 : 1,
            'message' => $context->expectsJson() ? null : $this->renderMessage($payload),
            'payload' => $context->expectsJson() ? $payload : null,
        ];
    }

    /**
     * @param array<int,string> $args
     */
    private function parse(array $args): Intent
    {
        $parts = [];
        $mode = null;
        $target = null;
        $workflowPath = null;
        $multiStep = false;
        $interactive = false;
        $dryRun = false;
        $policyCheck = false;
        $skipVerify = false;
        $explainAfter = false;
        $allowRisky = false;
        $allowPolicyViolations = false;
        $allowDirty = false;
        $allowPackInstall = false;
        $gitCommit = false;
        $gitCommitMessage = null;
        $packHints = [];
        $skipNext = false;

        foreach ($args as $index => $arg) {
            if ($skipNext) {
                $skipNext = false;
                continue;
            }

            if ($index === 0) {
                continue;
            }

            if ($arg === '--dry-run') {
                $dryRun = true;
                continue;
            }

            if ($arg === '--multi-step') {
                $multiStep = true;
                continue;
            }

            if ($arg === '--policy-check') {
                $policyCheck = true;
                continue;
            }

            if ($arg === '--interactive' || $arg === '-i') {
                $interactive = true;
                continue;
            }

            if ($arg === '--no-verify') {
                $skipVerify = true;
                continue;
            }

            if ($arg === '--explain') {
                $explainAfter = true;
                continue;
            }

            if ($arg === '--allow-risky') {
                $allowRisky = true;
                continue;
            }

            if ($arg === '--allow-policy-violations') {
                $allowPolicyViolations = true;
                continue;
            }

            if ($arg === '--allow-dirty') {
                $allowDirty = true;
                continue;
            }

            if ($arg === '--allow-pack-install') {
                $allowPackInstall = true;
                continue;
            }

            if ($arg === '--git-commit') {
                $gitCommit = true;
                continue;
            }

            if (str_starts_with($arg, '--mode=')) {
                $mode = trim(substr($arg, strlen('--mode=')));
                continue;
            }

            if ($arg === '--mode') {
                $mode = trim((string) ($args[$index + 1] ?? ''));
                $skipNext = true;
                continue;
            }

            if (str_starts_with($arg, '--target=')) {
                $target = trim(substr($arg, strlen('--target=')));
                continue;
            }

            if (str_starts_with($arg, '--workflow=')) {
                $workflowPath = trim(substr($arg, strlen('--workflow=')));
                continue;
            }

            if ($arg === '--workflow') {
                $workflowPath = trim((string) ($args[$index + 1] ?? ''));
                $skipNext = true;
                continue;
            }

            if ($arg === '--target') {
                $target = trim((string) ($args[$index + 1] ?? ''));
                $skipNext = true;
                continue;
            }

            if (str_starts_with($arg, '--packs=')) {
                $packHints = $this->parsePackList(substr($arg, strlen('--packs=')));
                continue;
            }

            if ($arg === '--packs') {
                $packHints = $this->parsePackList((string) ($args[$index + 1] ?? ''));
                $skipNext = true;
                continue;
            }

            if (str_starts_with($arg, '--git-commit-message=')) {
                $gitCommitMessage = trim(substr($arg, strlen('--git-commit-message=')));
                continue;
            }

            if ($arg === '--git-commit-message') {
                $gitCommitMessage = trim((string) ($args[$index + 1] ?? ''));
                $skipNext = true;
                continue;
            }

            if (str_starts_with($arg, '--')) {
                continue;
            }

            $parts[] = $arg;
        }

        $rawIntent = trim(implode(' ', $parts));
        if ($workflowPath !== null && $workflowPath !== '') {
            if ($rawIntent !== '') {
                throw new FoundryError(
                    'GENERATE_WORKFLOW_INTENT_CONFLICT',
                    'validation',
                    [],
                    'Generate workflow runs do not accept a free-form top-level intent.',
                );
            }

            if ($mode !== null && $mode !== '') {
                throw new FoundryError(
                    'GENERATE_WORKFLOW_MODE_CONFLICT',
                    'validation',
                    [],
                    'Generate workflow steps declare their own modes; omit top-level --mode.',
                );
            }

            if ($target !== null && $target !== '') {
                throw new FoundryError(
                    'GENERATE_WORKFLOW_TARGET_CONFLICT',
                    'validation',
                    [],
                    'Generate workflow steps declare their own targets; omit top-level --target.',
                );
            }

            if ($gitCommit) {
                throw new FoundryError(
                    'GENERATE_WORKFLOW_GIT_COMMIT_UNSUPPORTED',
                    'validation',
                    [],
                    'Generate workflow does not support top-level --git-commit yet.',
                );
            }

            if ($explainAfter) {
                throw new FoundryError(
                    'GENERATE_WORKFLOW_EXPLAIN_UNSUPPORTED',
                    'validation',
                    [],
                    'Generate workflow does not support top-level --explain yet.',
                );
            }

            return new Intent(
                raw: 'workflow ' . $workflowPath,
                mode: 'workflow',
                target: null,
                workflowPath: $workflowPath,
                multiStep: $multiStep,
                interactive: $interactive,
                dryRun: $dryRun,
                policyCheck: $policyCheck,
                skipVerify: $skipVerify,
                explainAfter: false,
                allowRisky: $allowRisky,
                allowPolicyViolations: $allowPolicyViolations,
                allowDirty: $allowDirty,
                allowPackInstall: $allowPackInstall,
                gitCommit: false,
                gitCommitMessage: null,
                packHints: $packHints,
            );
        }

        if ($multiStep) {
            throw new FoundryError(
                'GENERATE_MULTI_STEP_WORKFLOW_REQUIRED',
                'validation',
                [],
                'Generate requires --workflow when --multi-step is used.',
            );
        }

        if ($rawIntent === '') {
            throw new FoundryError(
                'GENERATE_INTENT_REQUIRED',
                'validation',
                [],
                'A generation intent is required.',
            );
        }

        if ($mode === null || $mode === '') {
            throw new FoundryError(
                'GENERATE_MODE_REQUIRED',
                'validation',
                [],
                'Generate requires --mode=new|modify|repair.',
            );
        }

        if (!in_array($mode, Intent::supportedModes(), true)) {
            throw new FoundryError(
                'GENERATE_MODE_INVALID',
                'validation',
                ['mode' => $mode],
                'Generate mode must be new, modify, or repair.',
            );
        }

        if (in_array($mode, ['modify', 'repair'], true) && trim((string) $target) === '') {
            throw new FoundryError(
                'GENERATE_TARGET_REQUIRED',
                'validation',
                ['mode' => $mode],
                'Generate requires --target for modify and repair modes.',
            );
        }

        if (($dryRun || $policyCheck) && $gitCommit) {
            throw new FoundryError(
                'GENERATE_GIT_COMMIT_DRY_RUN_INVALID',
                'validation',
                [],
                'Generate cannot use --git-commit together with --dry-run or --policy-check.',
            );
        }

        return new Intent(
            raw: $rawIntent,
            mode: $mode,
            target: $target,
            workflowPath: null,
            multiStep: false,
            interactive: $interactive,
            dryRun: $dryRun,
            policyCheck: $policyCheck,
            skipVerify: $skipVerify,
            explainAfter: $explainAfter,
            allowRisky: $allowRisky,
            allowPolicyViolations: $allowPolicyViolations,
            allowDirty: $allowDirty,
            allowPackInstall: $allowPackInstall,
            gitCommit: $gitCommit,
            gitCommitMessage: $gitCommitMessage !== '' ? $gitCommitMessage : null,
            packHints: $packHints,
        );
    }

    private function interactiveReviewer(CommandContext $context): InteractiveGenerateReviewer
    {
        if ($this->interactiveReviewerFactory instanceof \Closure) {
            return ($this->interactiveReviewerFactory)($context);
        }

        $writer = $context->expectsJson()
            ? static function (string $text): void {
                if (defined('STDERR')) {
                    fwrite(STDERR, $text);

                    return;
                }

                echo $text;
            }
            : static function (string $text): void {
                echo $text;
            };

        return new TerminalInteractiveGenerateReviewer(
            outputWriter: $writer,
        );
    }

    /**
     * @return array<int,string>
     */
    private function parsePackList(string $value): array
    {
        $packs = array_values(array_filter(array_map(
            static fn(string $pack): string => trim($pack),
            explode(',', $value),
        )));
        $packs = array_values(array_unique($packs));
        sort($packs);

        return $packs;
    }

    /**
     * @param array<int,string> $args
     */
    private function hasWorkflowFlag(array $args): bool
    {
        foreach ($args as $index => $arg) {
            if ($index === 0) {
                continue;
            }

            if ($arg === '--workflow' || str_starts_with($arg, '--workflow=') || $arg === '--multi-step') {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string,mixed> $payload
     */
    private function renderMessage(array $payload): string
    {
        if (is_array($payload['workflow'] ?? null)) {
            return $this->renderWorkflowMessage($payload);
        }

        $feature = trim((string) ($payload['plan']['metadata']['feature'] ?? ''));
        $files = count((array) ($payload['plan']['affected_files'] ?? []));
        $generator = (string) ($payload['plan']['generator_id'] ?? 'generate');
        $packs = array_values(array_map('strval', (array) ($payload['packs_used'] ?? [])));
        $packSummary = $packs === [] ? 'none' : implode(', ', $packs);
        $interactive = is_array($payload['interactive'] ?? null) ? $payload['interactive'] : [];
        $interactiveRejected = ($interactive['enabled'] ?? false) === true && ($interactive['approved'] ?? false) !== true;
        $safetyRouting = is_array($payload['safety_routing'] ?? null) ? $payload['safety_routing'] : [];

        $lines = [
            ($payload['metadata']['policy_check'] ?? false) === true
                ? 'Generate policy check completed.'
                : ($interactiveRejected
                ? 'Generate aborted before execution.'
                : (($payload['metadata']['dry_run'] ?? false) ? 'Generate plan prepared.' : 'Generate completed.')),
            'Mode: ' . (string) ($payload['mode'] ?? 'new'),
            'Generator: ' . $generator,
            'Files affected: ' . $files,
            'Packs: ' . $packSummary,
        ];

        if ($safetyRouting !== []) {
            $recommendedMode = str_replace('_', '-', (string) ($safetyRouting['recommended_mode'] ?? 'non_interactive'));
            $lines[] = 'Safety routing: ' . $recommendedMode;
        }

        if (($interactive['enabled'] ?? false) === true) {
            $lines[] = 'Interactive: ' . (($interactive['approved'] ?? false) === true ? 'approved' : 'rejected');
            $riskLevel = trim((string) ($interactive['risk']['level'] ?? ''));
            if ($riskLevel !== '') {
                $lines[] = 'Interactive risk: ' . $riskLevel;
            }
        }

        $policy = is_array($payload['policy'] ?? null) ? $payload['policy'] : [];
        if ($policy !== []) {
            $status = strtoupper((string) ($policy['status'] ?? 'pass'));
            $lines[] = 'Policy: ' . $status;
            if (($policy['loaded'] ?? false) === true) {
                $lines[] = 'Policy file: ' . (string) ($policy['path'] ?? '.foundry/policies/generate.json');
            } else {
                $lines[] = 'Policy file: not loaded';
            }

            $matchedRules = array_values(array_filter(array_map('strval', (array) ($policy['matched_rule_ids'] ?? []))));
            if ($matchedRules !== []) {
                $lines[] = 'Policy rules: ' . implode(', ', $matchedRules);
            }

            if (($policy['override_used'] ?? false) === true) {
                $lines[] = 'Policy override: applied';
            } elseif (($policy['override_available'] ?? false) === true) {
                $lines[] = 'Policy override: required for execution';
            }

            $violations = array_values(array_filter((array) ($policy['violations'] ?? []), 'is_array'));
            $warnings = array_values(array_filter((array) ($policy['warnings'] ?? []), 'is_array'));
            if ($violations !== []) {
                $lines[] = 'Policy violation: ' . (string) ($violations[0]['message'] ?? $violations[0]['description'] ?? 'Blocking policy violation.');
            }
            if ($warnings !== []) {
                $lines[] = 'Policy warning: ' . (string) ($warnings[0]['message'] ?? $warnings[0]['description'] ?? 'Policy warning.');
            }
        }

        $planConfidence = is_array($payload['plan_confidence'] ?? null) ? $payload['plan_confidence'] : [];
        if ($planConfidence !== []) {
            $lines[] = 'Plan confidence: ' . $this->formatConfidence($planConfidence);
        }

        if ($feature !== '') {
            $lines[] = 'Feature: ' . $feature;
        }

        $git = is_array($payload['git'] ?? null) ? $payload['git'] : [];
        if ($git !== [] && ($git['available'] ?? false) === true) {
            $before = is_array($git['before'] ?? null) ? $git['before'] : [];
            $after = is_array($git['after'] ?? null) ? $git['after'] : [];
            $branch = (string) (($after['branch'] ?? $before['branch']) ?? '');
            $head = (string) (($after['head'] ?? $before['head']) ?? '');
            if ($branch !== '' || $head !== '') {
                $lines[] = sprintf(
                    'Git: %s%s',
                    $branch !== '' ? $branch : 'detached',
                    $head !== '' ? ' @ ' . substr($head, 0, 12) : '',
                );
            }
        }

        $verification = is_array($payload['verification_results'] ?? null) ? $payload['verification_results'] : [];
        if (($verification['skipped'] ?? false) === true) {
            $lines[] = 'Verification: skipped';
        } else {
            $lines[] = 'Verification: ' . ((bool) ($verification['ok'] ?? false) ? 'passed' : 'failed');
        }

        $outcomeConfidence = is_array($payload['outcome_confidence'] ?? null) ? $payload['outcome_confidence'] : [];
        if ($outcomeConfidence !== []) {
            $lines[] = 'Outcome confidence: ' . $this->formatConfidence($outcomeConfidence);
            $warnings = array_values(array_filter(array_map('strval', (array) ($outcomeConfidence['warnings'] ?? []))));
            if ($warnings !== [] && in_array((string) ($outcomeConfidence['band'] ?? ''), ['medium', 'low', 'very_low'], true)) {
                $lines[] = 'Note: ' . $warnings[0];
            }
        }

        $gitWarnings = array_values(array_filter(array_map('strval', (array) ($git['warnings'] ?? []))));
        if ($gitWarnings !== []) {
            $lines[] = 'Git note: ' . $gitWarnings[0];
        }

        $gitCommit = is_array($git['commit'] ?? null) ? $git['commit'] : [];
        if (($gitCommit['created'] ?? false) === true) {
            $lines[] = 'Git commit: ' . substr((string) ($gitCommit['commit'] ?? ''), 0, 12);
        } elseif (($gitCommit['requested'] ?? false) === true && isset($gitCommit['warning'])) {
            $lines[] = 'Git commit skipped: ' . (string) $gitCommit['warning'];
        }

        $diff = is_array($payload['architecture_diff'] ?? null) ? $payload['architecture_diff'] : null;
        if ($diff !== null && ($payload['metadata']['dry_run'] ?? false) !== true) {
            $summary = $this->renderDiffSummary($diff);
            if ($summary !== []) {
                $lines[] = '';
                $lines[] = 'Summary:';
                foreach ($summary as $line) {
                    $lines[] = '- ' . $line;
                }
            }
        }

        $postExplainRendered = trim((string) ($payload['post_explain_rendered'] ?? ''));
        if ($postExplainRendered !== '') {
            $lines[] = '';
            $lines[] = 'Updated system:';
            $lines[] = $postExplainRendered;
        }

        if (($payload['metadata']['dry_run'] ?? false) !== true
            && ($payload['metadata']['policy_check'] ?? false) !== true
            && !$interactiveRejected) {
            $lines[] = '';
            $lines[] = 'Next:';
            $lines[] = '- Inspect architectural changes:';
            $lines[] = '    foundry explain --diff';
            $lines[] = '- View full current system:';
            $lines[] = '    foundry explain';
            $lines[] = '- Continue iterating:';
            $lines[] = '    ' . $this->refineCommand($payload);
        }

        return implode(PHP_EOL, $lines);
    }

    /**
     * @param array<string,mixed> $payload
     */
    private function renderWorkflowMessage(array $payload): string
    {
        $workflow = is_array($payload['workflow'] ?? null) ? $payload['workflow'] : [];
        $steps = array_values(array_filter((array) ($workflow['steps'] ?? []), 'is_array'));
        $completed = count(array_filter(
            $steps,
            static fn(array $step): bool => (string) ($step['status'] ?? '') === 'complete',
        ));

        $lines = [
            ($payload['ok'] ?? false) === true ? 'Generate workflow completed.' : 'Generate workflow failed.',
            'Workflow: ' . (string) ($workflow['id'] ?? ''),
            'Source: ' . (string) ($workflow['path'] ?? ''),
            'Status: ' . (string) ($workflow['status'] ?? (($payload['ok'] ?? false) === true ? 'success' : 'failed')),
            sprintf('Steps: %d/%d complete', $completed, count($steps)),
        ];

        $failedStepId = trim((string) ($workflow['failed_step_id'] ?? ''));
        if ($failedStepId !== '') {
            $lines[] = 'Failed step: ' . $failedStepId;
        }

        $lines[] = '';
        $lines[] = 'Step progression:';
        foreach ($steps as $step) {
            $lines[] = sprintf(
                '- [%s] %s: %s',
                (string) ($step['status'] ?? 'pending'),
                (string) ($step['id'] ?? 'step'),
                (string) ($step['description'] ?? 'Generate workflow step'),
            );
        }

        $rollbackGuidance = array_values(array_filter(array_map('strval', (array) ($workflow['rollback_guidance'] ?? []))));
        if ($rollbackGuidance !== []) {
            $lines[] = '';
            $lines[] = 'Rollback guidance:';
            foreach ($rollbackGuidance as $guidance) {
                $lines[] = '- ' . $guidance;
            }
        }

        return implode(PHP_EOL, $lines);
    }

    /**
     * @param array<string,mixed> $diff
     * @return array<int,string>
     */
    private function renderDiffSummary(array $diff): array
    {
        $lines = [];
        foreach (['added' => 'Added', 'modified' => 'Modified', 'removed' => 'Removed'] as $key => $label) {
            $items = array_values(array_filter((array) ($diff[$key] ?? []), 'is_array'));
            if ($items === []) {
                continue;
            }

            $names = [];
            foreach (array_slice($items, 0, 3) as $item) {
                $name = trim((string) ($item['label'] ?? $item['id'] ?? ''));
                $extension = trim((string) ($item['extension'] ?? ''));
                if ($name === '') {
                    continue;
                }

                if ($extension !== '' && $extension !== $name) {
                    $name .= ' [' . $extension . ']';
                }

                $names[] = $name;
            }

            $summary = $label . ': ' . implode('; ', $names);
            if (count($items) > count($names)) {
                $summary .= sprintf(' (+%d more)', count($items) - count($names));
            }

            $lines[] = $summary;
        }

        if ($lines === []) {
            $lines[] = 'No architectural changes detected.';
        }

        return $lines;
    }

    /**
     * @param array<string,mixed> $payload
     */
    private function refineCommand(array $payload): string
    {
        $feature = trim((string) ($payload['plan']['metadata']['feature'] ?? ''));
        if ($feature !== '') {
            return sprintf('foundry generate "Refine %s" --mode=modify --target=%s', $feature, $feature);
        }

        $resolved = trim((string) ($payload['metadata']['target']['resolved'] ?? ''));
        if ($resolved !== '') {
            return sprintf('foundry generate "Refine target" --mode=modify --target=%s', $resolved);
        }

        return 'foundry generate "Refine feature" --mode=modify --target=<target>';
    }

    /**
     * @param array<string,mixed> $confidence
     */
    private function formatConfidence(array $confidence): string
    {
        return sprintf(
            '%s (%.2f)',
            str_replace('_', ' ', (string) ($confidence['band'] ?? 'unknown')),
            (float) ($confidence['score'] ?? 0.0),
        );
    }
}
