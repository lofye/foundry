<?php

declare(strict_types=1);

namespace Foundry\CLI\Commands;

use Foundry\CLI\Command;
use Foundry\CLI\CommandContext;
use Foundry\Generate\GenerateEngine;
use Foundry\Generate\Intent;
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

    public function __construct(private readonly ?PackManager $packManager = null) {}

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
        $dryRun = false;
        $skipVerify = false;
        $explainAfter = false;
        $allowRisky = false;
        $allowPackInstall = false;
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

            if ($arg === '--allow-pack-install') {
                $allowPackInstall = true;
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

        return new Intent(
            raw: $rawIntent,
            mode: $mode,
            target: $target,
            dryRun: $dryRun,
            skipVerify: $skipVerify,
            explainAfter: $explainAfter,
            allowRisky: $allowRisky,
            allowPackInstall: $allowPackInstall,
            packHints: $packHints,
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

        $lines = [
            ($payload['metadata']['dry_run'] ?? false) ? 'Generate plan prepared.' : 'Generate completed.',
            'Mode: ' . (string) ($payload['mode'] ?? 'new'),
            'Generator: ' . $generator,
            'Files affected: ' . $files,
            'Packs: ' . $packSummary,
        ];

        $planConfidence = is_array($payload['plan_confidence'] ?? null) ? $payload['plan_confidence'] : [];
        if ($planConfidence !== []) {
            $lines[] = 'Plan confidence: ' . $this->formatConfidence($planConfidence);
        }

        if ($feature !== '') {
            $lines[] = 'Feature: ' . $feature;
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

        if (($payload['metadata']['dry_run'] ?? false) !== true) {
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
