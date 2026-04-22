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
            'status' => 0,
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
        $interactive = false;
        $dryRun = false;
        $skipVerify = false;
        $explainAfter = false;
        $allowRisky = false;
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

        if ($dryRun && $gitCommit) {
            throw new FoundryError(
                'GENERATE_GIT_COMMIT_DRY_RUN_INVALID',
                'validation',
                [],
                'Generate cannot use --git-commit together with --dry-run.',
            );
        }

        return new Intent(
            raw: $rawIntent,
            mode: $mode,
            target: $target,
            interactive: $interactive,
            dryRun: $dryRun,
            skipVerify: $skipVerify,
            explainAfter: $explainAfter,
            allowRisky: $allowRisky,
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
     * @param array<string,mixed> $payload
     */
    private function renderMessage(array $payload): string
    {
        $feature = trim((string) ($payload['plan']['metadata']['feature'] ?? ''));
        $files = count((array) ($payload['plan']['affected_files'] ?? []));
        $generator = (string) ($payload['plan']['generator_id'] ?? 'generate');
        $packs = array_values(array_map('strval', (array) ($payload['packs_used'] ?? [])));
        $packSummary = $packs === [] ? 'none' : implode(', ', $packs);
        $interactive = is_array($payload['interactive'] ?? null) ? $payload['interactive'] : [];
        $interactiveRejected = ($interactive['enabled'] ?? false) === true && ($interactive['approved'] ?? false) !== true;
        $safetyRouting = is_array($payload['safety_routing'] ?? null) ? $payload['safety_routing'] : [];

        $lines = [
            $interactiveRejected
                ? 'Generate aborted before execution.'
                : (($payload['metadata']['dry_run'] ?? false) ? 'Generate plan prepared.' : 'Generate completed.'),
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

        if (($payload['metadata']['dry_run'] ?? false) !== true && !$interactiveRejected) {
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
