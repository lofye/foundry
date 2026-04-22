<?php

declare(strict_types=1);

namespace Foundry\Generate;

use Foundry\Support\FoundryError;

final class TerminalInteractiveGenerateReviewer implements InteractiveGenerateReviewer
{
    private readonly GeneratePlanPreviewBuilder $previewBuilder;
    private readonly GeneratePlanRiskAnalyzer $riskAnalyzer;
    private readonly PlanValidator $validator;

    public function __construct(
        private readonly \Closure|null $inputReader = null,
        private readonly \Closure|null $outputWriter = null,
        private readonly ?bool $interactive = null,
        ?GeneratePlanPreviewBuilder $previewBuilder = null,
        ?GeneratePlanRiskAnalyzer $riskAnalyzer = null,
        ?PlanValidator $validator = null,
    ) {
        $this->previewBuilder = $previewBuilder ?? new GeneratePlanPreviewBuilder(\Foundry\Support\Paths::fromCwd());
        $this->riskAnalyzer = $riskAnalyzer ?? new GeneratePlanRiskAnalyzer();
        $this->validator = $validator ?? new PlanValidator();
    }

    #[\Override]
    public function review(InteractiveGenerateReviewRequest $request): InteractiveGenerateReviewResult
    {
        if (!$this->isInteractive() && !$this->inputReader instanceof \Closure) {
            throw new FoundryError(
                'GENERATE_INTERACTIVE_TTY_REQUIRED',
                'validation',
                [],
                'Interactive generate requires a TTY or an injected input stream.',
            );
        }

        $originalPlan = $request->plan;
        $currentPlan = $originalPlan;
        $excludedActionIndexes = [];
        $decisions = [];
        $renderPlan = true;

        while (true) {
            $preview = $this->previewBuilder->build($currentPlan, $request->intent);
            $risk = $this->riskAnalyzer->analyze($currentPlan);

            if ($renderPlan) {
                $this->renderPlan($request, $currentPlan, $risk, $preview);
                $renderPlan = false;
            }

            $rawInput = trim($this->readLine('Decision [approve/reject/inspect/exclude/toggle risky/help]: '));
            $normalized = strtolower($rawInput);

            if ($normalized === '') {
                continue;
            }

            if (in_array($normalized, ['approve', 'a', 'yes', 'y'], true)) {
                $allowRisky = (bool) $request->intent->allowRisky;
                if ($risk['level'] === 'HIGH') {
                    $decisions[] = ['type' => 'approve_attempt', 'risk_level' => 'HIGH'];
                    if (!$this->confirm('High-risk actions remain. Approve risky execution? [y/N]: ')) {
                        $decisions[] = ['type' => 'risk_confirmation', 'approved' => false];
                        $this->writeLine('Risky execution not approved. Review continues.');
                        continue;
                    }

                    $allowRisky = true;
                    $decisions[] = ['type' => 'risk_confirmation', 'approved' => true];
                }

                $executionIntent = $allowRisky ? $request->intent->withAllowRisky(true) : $request->intent;
                $this->validator->validate($currentPlan, $executionIntent);
                $decisions[] = ['type' => 'approve', 'risk_level' => $risk['level']];

                return new InteractiveGenerateReviewResult(
                    approved: true,
                    plan: $currentPlan,
                    userDecisions: $decisions,
                    preview: $this->interactivePayload($request, $currentPlan, $preview, $risk),
                    risk: $risk,
                    allowRisky: $allowRisky,
                    modified: $this->planFingerprint($currentPlan) !== $this->planFingerprint($originalPlan),
                );
            }

            if (in_array($normalized, ['reject', 'r', 'abort', 'no', 'n'], true)) {
                $decisions[] = ['type' => 'reject'];

                return new InteractiveGenerateReviewResult(
                    approved: false,
                    plan: $currentPlan,
                    userDecisions: $decisions,
                    preview: $this->interactivePayload($request, $currentPlan, $preview, $risk),
                    risk: $risk,
                    allowRisky: false,
                    modified: $this->planFingerprint($currentPlan) !== $this->planFingerprint($originalPlan),
                );
            }

            if (in_array($normalized, ['help', 'h', '?'], true)) {
                $decisions[] = ['type' => 'help'];
                $this->renderHelp();

                continue;
            }

            if ($normalized === 'inspect graph') {
                $decisions[] = ['type' => 'inspect_graph'];
                $this->renderGraphInspection($request);

                continue;
            }

            if ($normalized === 'inspect explain') {
                $decisions[] = ['type' => 'inspect_explain'];
                $this->renderExplainInspection($request);

                continue;
            }

            if (preg_match('/^inspect\s+action\s+(\d+)$/', $normalized, $matches) === 1) {
                $index = max(0, ((int) $matches[1]) - 1);
                $decisions[] = ['type' => 'inspect_action', 'action_index' => $index];
                $this->renderActionInspection($currentPlan, $preview, $index);

                continue;
            }

            if (preg_match('/^exclude\s+action\s+(\d+)$/', $normalized, $matches) === 1) {
                $index = max(0, ((int) $matches[1]) - 1);
                $decisions[] = ['type' => 'exclude_action', 'action_index' => $index];

                if (!array_key_exists($index, $originalPlan->actions)) {
                    $this->writeLine('Action index is out of range.');
                    continue;
                }

                $excludedActionIndexes[$index] = true;
                $candidate = $this->modifiedPlan($originalPlan, array_keys($excludedActionIndexes));
                if ($candidate === null) {
                    unset($excludedActionIndexes[$index]);
                    $this->writeLine('Interactive modifications cannot remove every action from the plan.');
                    continue;
                }

                $this->validator->validate($candidate, $request->intent, true);
                $currentPlan = $candidate;
                $renderPlan = true;
                $this->writeLine('Excluded action ' . ($index + 1) . '.');

                continue;
            }

            if (preg_match('/^exclude\s+file\s+(.+)$/i', $rawInput, $matches) === 1) {
                $input = trim((string) $matches[1]);
                $path = $this->resolveFileSelection($originalPlan, $input);
                $decisions[] = ['type' => 'exclude_file', 'path' => $path ?? $input];

                if ($path === null) {
                    $this->writeLine('No plan file matched that selection.');
                    continue;
                }

                foreach ($originalPlan->actions as $index => $action) {
                    if ((string) ($action['path'] ?? '') === $path) {
                        $excludedActionIndexes[$index] = true;
                    }
                }

                $candidate = $this->modifiedPlan($originalPlan, array_keys($excludedActionIndexes));
                if ($candidate === null) {
                    foreach ($originalPlan->actions as $index => $action) {
                        if ((string) ($action['path'] ?? '') === $path) {
                            unset($excludedActionIndexes[$index]);
                        }
                    }

                    $this->writeLine('Interactive modifications cannot remove every action from the plan.');
                    continue;
                }

                $this->validator->validate($candidate, $request->intent, true);
                $currentPlan = $candidate;
                $renderPlan = true;
                $this->writeLine('Excluded file `' . $path . '`.');

                continue;
            }

            if ($normalized === 'toggle risky') {
                $baseRisk = $this->riskAnalyzer->analyze($originalPlan);
                $decisions[] = ['type' => 'toggle_risky'];

                if (($baseRisk['risky_action_indexes'] ?? []) === []) {
                    $this->writeLine('No risky actions are currently present in the original plan.');
                    continue;
                }

                $allExcluded = true;
                foreach ($baseRisk['risky_action_indexes'] as $index) {
                    if (!isset($excludedActionIndexes[$index])) {
                        $allExcluded = false;
                        break;
                    }
                }

                foreach ($baseRisk['risky_action_indexes'] as $index) {
                    if ($allExcluded) {
                        unset($excludedActionIndexes[$index]);
                    } else {
                        $excludedActionIndexes[$index] = true;
                    }
                }

                $candidate = $this->modifiedPlan($originalPlan, array_keys($excludedActionIndexes));
                if ($candidate === null) {
                    $excludedActionIndexes = [];
                    $this->writeLine('Risk toggle would remove every action from the plan.');
                    continue;
                }

                $this->validator->validate($candidate, $request->intent, true);
                $currentPlan = $candidate;
                $renderPlan = true;
                $this->writeLine($allExcluded ? 'Restored risky actions.' : 'Excluded risky actions.');

                continue;
            }

            $this->writeLine('Unknown review command. Type `help` for supported actions.');
        }
    }

    /**
     * @param array<string,mixed> $risk
     * @param array{files:list<array<string,mixed>>} $preview
     */
    private function renderPlan(
        InteractiveGenerateReviewRequest $request,
        GenerationPlan $plan,
        array $risk,
        array $preview,
    ): void {
        $targets = array_values(array_filter(array_map(
            static function (array $target): ?string {
                $resolved = trim((string) ($target['resolved'] ?? ''));
                if ($resolved !== '') {
                    return $resolved;
                }

                $requested = trim((string) ($target['requested'] ?? ''));

                return $requested !== '' ? $requested : null;
            },
            $request->context->targets,
        )));

        $this->writeLine('');
        $this->writeLine('Interactive generate review');
        $this->writeLine('Summary:');
        $this->writeLine('  Intent: ' . $request->intent->raw);
        $this->writeLine('  Mode: ' . $request->intent->mode);
        $this->writeLine('  Targets: ' . ($targets === [] ? 'system' : implode(', ', $targets)));
        $this->writeLine('  Total actions: ' . count($plan->actions));
        $this->writeLine('  Affected files: ' . count($plan->affectedFiles));
        $this->writeLine('  Risk level: ' . (string) ($risk['level'] ?? 'LOW'));
        $this->writeLine('  Verification: ' . implode(', ', $plan->validations !== [] ? $plan->validations : $request->context->validationSteps));
        $this->writeLine('');
        $this->writeLine('Detail:');

        foreach ($plan->actions as $index => $action) {
            $path = trim((string) ($action['path'] ?? ''));
            $summary = trim((string) ($action['summary'] ?? 'No summary provided.'));
            $dependencies = $this->actionDependencies($action);
            $relatedNodes = $this->actionRelatedNodes($plan, $action);

            $this->writeLine(sprintf('  %d. %s %s', $index + 1, (string) ($action['type'] ?? 'action'), $path !== '' ? '(' . $path . ')' : ''));
            $this->writeLine('     Summary: ' . $summary);
            $this->writeLine('     Dependencies: ' . ($dependencies === [] ? 'none' : implode(', ', $dependencies)));
            $this->writeLine('     Related graph nodes: ' . ($relatedNodes === [] ? 'none' : implode(', ', $relatedNodes)));
        }

        if ($preview['files'] === []) {
            return;
        }

        $this->writeLine('');
        $this->writeLine('Diffs:');
        foreach ($preview['files'] as $file) {
            $this->writeLine('  File: ' . (string) ($file['path'] ?? ''));
            $this->write((string) ($file['unified_diff'] ?? ''));
        }
    }

    private function renderHelp(): void
    {
        $this->writeLine('');
        $this->writeLine('Supported review commands:');
        $this->writeLine('  approve');
        $this->writeLine('  reject');
        $this->writeLine('  inspect action <n>');
        $this->writeLine('  inspect graph');
        $this->writeLine('  inspect explain');
        $this->writeLine('  exclude action <n>');
        $this->writeLine('  exclude file <path|n>');
        $this->writeLine('  toggle risky');
        $this->writeLine('  help');
    }

    private function renderGraphInspection(InteractiveGenerateReviewRequest $request): void
    {
        $subject = $request->context->model->subject;
        $graph = is_array($request->context->model->relationships['graph'] ?? null)
            ? $request->context->model->relationships['graph']
            : [];

        $this->writeLine('');
        $this->writeLine('Graph inspection:');
        $this->writeLine('  Subject: ' . (string) ($subject['id'] ?? 'system:root'));
        $this->writeLine('  Kind: ' . (string) ($subject['kind'] ?? 'system'));

        foreach (['inbound', 'outbound', 'lateral'] as $direction) {
            $rows = array_values(array_filter((array) ($graph[$direction] ?? []), 'is_array'));
            $labels = array_values(array_filter(array_map(
                static fn(array $row): ?string => trim((string) ($row['id'] ?? $row['label'] ?? '')) ?: null,
                $rows,
            )));

            $this->writeLine('  ' . ucfirst($direction) . ': ' . ($labels === [] ? 'none' : implode(', ', array_slice($labels, 0, 6))));
        }
    }

    private function renderExplainInspection(InteractiveGenerateReviewRequest $request): void
    {
        $rendered = trim((string) ($request->explainRendered ?? ''));

        $this->writeLine('');
        $this->writeLine('Explain inspection:');
        if ($rendered === '') {
            $this->writeLine('  Explain output is not available for this target.');

            return;
        }

        foreach (preg_split('/\R/', $rendered) ?: [] as $line) {
            $this->writeLine('  ' . $line);
        }
    }

    /**
     * @param array{files:list<array<string,mixed>>} $preview
     */
    private function renderActionInspection(GenerationPlan $plan, array $preview, int $index): void
    {
        $action = $plan->actions[$index] ?? null;
        if (!is_array($action)) {
            $this->writeLine('Action index is out of range.');

            return;
        }

        $this->writeLine('');
        $this->writeLine('Action inspection:');
        $this->writeLine('  Type: ' . (string) ($action['type'] ?? 'action'));
        $this->writeLine('  Path: ' . (string) ($action['path'] ?? ''));
        $this->writeLine('  Summary: ' . (string) ($action['summary'] ?? 'No summary provided.'));
        $this->writeLine('  Dependencies: ' . implode(', ', $this->actionDependencies($action)));
        $this->writeLine('  Related graph nodes: ' . implode(', ', $this->actionRelatedNodes($plan, $action)));

        foreach ($preview['files'] as $file) {
            if ((int) ($file['action_index'] ?? -1) !== $index) {
                continue;
            }

            $this->write((string) ($file['unified_diff'] ?? ''));
        }
    }

    /**
     * @return list<string>
     */
    private function actionDependencies(array $action): array
    {
        $path = strtolower(trim((string) ($action['path'] ?? '')));
        $dependencies = [];

        if (str_contains($path, 'schema')) {
            $dependencies[] = 'contracts';
        }

        if (str_contains($path, 'queries.sql')) {
            $dependencies[] = 'database';
        }

        if (str_contains($path, 'permissions.yaml')) {
            $dependencies[] = 'auth';
        }

        if (str_contains($path, 'cache.yaml')) {
            $dependencies[] = 'cache';
        }

        if (str_contains($path, 'events.yaml')) {
            $dependencies[] = 'events';
        }

        if (str_contains($path, 'jobs.yaml')) {
            $dependencies[] = 'queue';
        }

        if (str_contains($path, '/tests/')) {
            $dependencies[] = 'testing';
        }

        if (str_contains($path, 'prompts.md')) {
            $dependencies[] = 'llm';
        }

        if ($dependencies === []) {
            $dependencies[] = 'feature';
        }

        return array_values(array_unique($dependencies));
    }

    /**
     * @return list<string>
     */
    private function actionRelatedNodes(GenerationPlan $plan, array $action): array
    {
        $nodes = [];
        $trace = trim((string) ($action['explain_node_id'] ?? ''));
        if ($trace !== '') {
            $nodes[] = $trace;
        }

        $feature = trim((string) ($plan->metadata['feature'] ?? ''));
        if ($feature !== '') {
            $nodes[] = 'feature:' . $feature;
        }

        return array_values(array_unique($nodes));
    }

    /**
     * @param array<int,int> $excludedIndexes
     */
    private function modifiedPlan(GenerationPlan $originalPlan, array $excludedIndexes): ?GenerationPlan
    {
        $excluded = array_fill_keys(array_map('intval', $excludedIndexes), true);
        $actions = [];

        foreach ($originalPlan->actions as $index => $action) {
            if (isset($excluded[$index])) {
                continue;
            }

            $actions[] = $action;
        }

        if ($actions === []) {
            return null;
        }

        $affectedFiles = array_values(array_unique(array_filter(array_map(
            static fn(array $action): string => trim((string) ($action['path'] ?? '')),
            $actions,
        ))));
        sort($affectedFiles);

        $risks = $this->planFingerprint($originalPlan) === $this->planFingerprint(new GenerationPlan(
            actions: $actions,
            affectedFiles: $affectedFiles,
            risks: $originalPlan->risks,
            validations: $originalPlan->validations,
            origin: $originalPlan->origin,
            generatorId: $originalPlan->generatorId,
            extension: $originalPlan->extension,
            metadata: $originalPlan->metadata,
            confidence: $originalPlan->confidence,
        ))
            ? $originalPlan->risks
            : ['Interactive review modified the original plan before execution.'];

        return new GenerationPlan(
            actions: $actions,
            affectedFiles: $affectedFiles,
            risks: $risks,
            validations: $originalPlan->validations,
            origin: $originalPlan->origin,
            generatorId: $originalPlan->generatorId,
            extension: $originalPlan->extension,
            metadata: $originalPlan->metadata,
            confidence: $originalPlan->confidence,
        );
    }

    private function resolveFileSelection(GenerationPlan $plan, string $input): ?string
    {
        if (ctype_digit($input)) {
            $index = ((int) $input) - 1;
            $files = $plan->affectedFiles;

            return $files[$index] ?? null;
        }

        return in_array($input, $plan->affectedFiles, true) ? $input : null;
    }

    /**
     * @param array{files:list<array<string,mixed>>} $preview
     * @param array<string,mixed> $risk
     * @return array<string,mixed>
     */
    private function interactivePayload(
        InteractiveGenerateReviewRequest $request,
        GenerationPlan $plan,
        array $preview,
        array $risk,
    ): array {
        $targets = array_values(array_filter(array_map(
            static fn(array $target): ?string => trim((string) ($target['resolved'] ?? $target['requested'] ?? '')) ?: null,
            $request->context->targets,
        )));

        $actions = [];
        foreach ($plan->actions as $index => $action) {
            $actions[] = [
                'index' => $index + 1,
                'type' => (string) ($action['type'] ?? 'action'),
                'path' => (string) ($action['path'] ?? ''),
                'summary' => (string) ($action['summary'] ?? ''),
                'dependencies' => $this->actionDependencies($action),
                'related_graph_nodes' => $this->actionRelatedNodes($plan, $action),
            ];
        }

        return [
            'summary' => [
                'intent' => $request->intent->raw,
                'mode' => $request->intent->mode,
                'targets' => $targets,
                'total_actions' => count($plan->actions),
                'affected_files' => count($plan->affectedFiles),
                'risk_level' => $risk['level'] ?? 'LOW',
                'verification_steps' => $plan->validations !== [] ? $plan->validations : $request->context->validationSteps,
            ],
            'actions' => $actions,
            'diffs' => $preview['files'],
        ];
    }

    private function planFingerprint(GenerationPlan $plan): string
    {
        return md5(serialize($plan->toArray()));
    }

    private function isInteractive(): bool
    {
        if ($this->interactive !== null) {
            return $this->interactive;
        }

        if (!defined('STDIN')) {
            return false;
        }

        if (function_exists('stream_isatty')) {
            return @stream_isatty(STDIN);
        }

        if (function_exists('posix_isatty')) {
            return @posix_isatty(STDIN);
        }

        return false;
    }

    private function confirm(string $prompt): bool
    {
        $answer = strtolower(trim($this->readLine($prompt)));

        return in_array($answer, ['y', 'yes'], true);
    }

    private function readLine(string $prompt): string
    {
        if ($this->inputReader instanceof \Closure) {
            $line = ($this->inputReader)($prompt);

            return is_string($line) ? trim($line) : '';
        }

        if (function_exists('readline')) {
            $line = readline($prompt);

            return is_string($line) ? trim($line) : '';
        }

        $this->write($prompt);
        $handle = fopen('php://stdin', 'r');
        if (!is_resource($handle)) {
            return '';
        }

        $line = fgets($handle);

        return is_string($line) ? trim($line) : '';
    }

    private function writeLine(string $line): void
    {
        $this->write($line . PHP_EOL);
    }

    private function write(string $text): void
    {
        if ($this->outputWriter instanceof \Closure) {
            ($this->outputWriter)($text);

            return;
        }

        echo $text;
    }
}
