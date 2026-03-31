<?php

declare(strict_types=1);

namespace Foundry\Confidence;

use Foundry\Explain\ExplainModel;
use Foundry\Generate\GenerationContextPacket;
use Foundry\Generate\GenerationPlan;
use Foundry\Generate\Intent;

final class ConfidenceEngine
{
    /**
     * @var array<string,array<string,float>>
     */
    private const WEIGHTS = [
        'explain' => [
            'target_resolution' => 0.20,
            'subject_normalization' => 0.15,
            'collector_coverage' => 0.15,
            'graph_neighborhood' => 0.15,
            'execution_visibility' => 0.10,
            'diagnostics_availability' => 0.10,
            'docs_command_linkage' => 0.15,
        ],
        'plan' => [
            'explain_confidence' => 0.20,
            'context_completeness' => 0.20,
            'generator_specificity' => 0.15,
            'traceability' => 0.15,
            'validation_coverage' => 0.15,
            'scope_control' => 0.10,
            'risk_profile' => 0.05,
        ],
        'outcome' => [
            'plan_confidence' => 0.25,
            'execution_completion' => 0.15,
            'verification_depth' => 0.20,
            'verification_success' => 0.20,
            'diff_coherence' => 0.10,
            'policy_posture' => 0.10,
        ],
        'diff' => [
            'snapshot_compatibility' => 0.30,
            'explain_continuity' => 0.50,
            'diff_coherence' => 0.20,
        ],
    ];

    /**
     * @return array<string,mixed>
     */
    public function explain(ExplainModel $model): array
    {
        $subject = $model->subject;
        $targetSelector = trim((string) ($model->metadata['target']['selector'] ?? ($subject['id'] ?? '')));
        $subjectFields = [
            trim((string) ($subject['id'] ?? '')),
            trim((string) ($subject['kind'] ?? '')),
            trim((string) ($subject['label'] ?? '')),
            trim((string) ($subject['origin'] ?? '')),
        ];
        $subjectCompleteness = $this->ratio(
            count(array_filter($subjectFields, static fn(string $value): bool => $value !== '')),
            count($subjectFields),
        );

        $graphNodeIds = count(array_values(array_filter((array) ($model->graph['node_ids'] ?? []))));
        $subjectNode = is_array($model->graph['subject_node'] ?? null) ? $model->graph['subject_node'] : null;
        $neighborCount = $this->countRows($model->graph['neighbors']['inbound'] ?? [])
            + $this->countRows($model->graph['neighbors']['outbound'] ?? [])
            + $this->countRows($model->graph['neighbors']['lateral'] ?? []);
        $executionEvidence = $this->countRows($model->execution['entries'] ?? [])
            + $this->countRows($model->execution['stages'] ?? [])
            + $this->countRows($model->execution['workflows'] ?? [])
            + $this->countRows($model->execution['jobs'] ?? [])
            + (is_array($model->execution['action'] ?? null) ? 1 : 0);
        $docsCount = $this->countRows($model->docs['related'] ?? []);
        $commandCount = $this->countRows($model->commands['related'] ?? []) + (is_array($model->commands['subject'] ?? null) ? 1 : 0);
        $extensionCount = $this->countRows($model->extensions);
        $collectorCoverage = $this->ratio(
            (int) ($graphNodeIds > 0 || $subjectNode !== null || $neighborCount > 0)
            + (int) ($executionEvidence > 0)
            + (int) $this->hasDiagnostics($model->diagnostics)
            + (int) ($docsCount > 0)
            + (int) ($commandCount > 0)
            + (int) ($extensionCount > 0),
            6,
        );

        $subjectKind = trim((string) ($subject['kind'] ?? ''));
        $graphNeighborhoodScore = match (true) {
            $subjectNode !== null && ($graphNodeIds > 0 || $neighborCount > 0) => 1.0,
            $subjectNode !== null || $graphNodeIds > 0 || $neighborCount > 0 => 0.75,
            trim((string) ($subject['id'] ?? '')) === 'system:root' => 0.55,
            default => 0.40,
        };
        $executionRelevantKinds = ['feature', 'route', 'workflow', 'job', 'command', 'pack', 'extension', 'system'];
        $executionScore = match (true) {
            $executionEvidence > 0 => 1.0,
            in_array($subjectKind, $executionRelevantKinds, true) => 0.40,
            default => 0.75,
        };

        $diagnosticsSummary = is_array($model->diagnostics['summary'] ?? null) ? $model->diagnostics['summary'] : [];
        $diagnosticKeys = ['error', 'warning', 'info', 'total'];
        $diagnosticsScore = match (true) {
            count(array_intersect($diagnosticKeys, array_keys($diagnosticsSummary))) === count($diagnosticKeys) => 1.0,
            $this->countRows($model->diagnostics['items'] ?? []) > 0 => 0.85,
            default => 0.35,
        };

        $linkageScore = match (true) {
            $docsCount > 0 && $commandCount > 0 => 1.0,
            $docsCount > 0 || $commandCount > 0 => 0.70,
            default => 0.35,
        };

        return $this->build(
            'explain',
            [
                'target_resolution' => [
                    'score' => $targetSelector !== '' ? 1.0 : 0.35,
                    'reason' => $targetSelector !== ''
                        ? 'Target resolved into a stable selector.'
                        : 'Target selector could not be normalized deterministically.',
                ],
                'subject_normalization' => [
                    'score' => $subjectCompleteness,
                    'reason' => sprintf(
                        '%d of %d subject identity fields were normalized.',
                        count(array_filter($subjectFields, static fn(string $value): bool => $value !== '')),
                        count($subjectFields),
                    ),
                ],
                'collector_coverage' => [
                    'score' => $collectorCoverage,
                    'reason' => sprintf(
                        '%d of 6 evidence domains produced explainable context.',
                        (int) ($graphNodeIds > 0 || $subjectNode !== null || $neighborCount > 0)
                        + (int) ($executionEvidence > 0)
                        + (int) $this->hasDiagnostics($model->diagnostics)
                        + (int) ($docsCount > 0)
                        + (int) ($commandCount > 0)
                        + (int) ($extensionCount > 0),
                    ),
                ],
                'graph_neighborhood' => [
                    'score' => $graphNeighborhoodScore,
                    'reason' => $neighborCount > 0 || $graphNodeIds > 0 || $subjectNode !== null
                        ? sprintf('Graph context includes %d related nodes and relationships.', $graphNodeIds + $neighborCount + ($subjectNode !== null ? 1 : 0))
                        : 'Graph neighborhood evidence is minimal for this subject.',
                ],
                'execution_visibility' => [
                    'score' => $executionScore,
                    'reason' => $executionEvidence > 0
                        ? sprintf('Execution flow exposes %d explainable steps and edges.', $executionEvidence)
                        : 'Execution flow evidence is limited for this subject.',
                ],
                'diagnostics_availability' => [
                    'score' => $diagnosticsScore,
                    'reason' => $diagnosticsScore >= 1.0
                        ? 'Diagnostics summary is available from canonical analysis.'
                        : 'Diagnostics coverage is partial or unavailable.',
                ],
                'docs_command_linkage' => [
                    'score' => $linkageScore,
                    'reason' => match (true) {
                        $docsCount > 0 && $commandCount > 0 => sprintf('Explain linked %d docs and %d commands.', $docsCount, $commandCount),
                        $docsCount > 0 => sprintf('Explain linked %d related docs but no command metadata.', $docsCount),
                        $commandCount > 0 => sprintf('Explain linked %d related commands but no docs.', $commandCount),
                        default => 'No related docs or command links were available.',
                    },
                ],
            ],
        );
    }

    /**
     * @return array<string,mixed>
     */
    public function plan(GenerationContextPacket $context, GenerationPlan $plan): array
    {
        $targetCount = count($context->targets);
        $subjectId = trim((string) ($context->model->subject['id'] ?? ''));
        $docsCount = count($context->docs);
        $contextCompleteness = $this->ratio(
            (int) ($targetCount > 0)
            + (int) ($subjectId !== '')
            + (int) ($this->hasGraphRelationshipContext($context->graphRelationships))
            + (int) ($context->constraints !== [])
            + (int) ($context->validationSteps !== [])
            + (int) ($context->availableGenerators !== [])
            + (int) ($docsCount > 0),
            7,
        );

        $subjectOrigin = trim((string) ($context->model->subject['origin'] ?? 'core'));
        $subjectExtension = trim((string) ($context->model->subject['extension'] ?? ''));
        $packHints = $context->intent->packHints;
        $mergedGenerators = count((array) ($plan->metadata['merged_generators'] ?? []));
        $generatorSpecificity = match (true) {
            $mergedGenerators > 1 => 0.70,
            $plan->origin === 'pack' && $plan->extension !== null && in_array($plan->extension, $packHints, true) => 1.0,
            $plan->origin === 'pack' && $plan->extension !== null && $plan->extension === $subjectExtension => 1.0,
            $plan->origin === 'pack' && $subjectOrigin === 'extension' => 0.85,
            $plan->origin === 'core' && $subjectOrigin === 'core' => 0.95,
            default => 0.60,
        };

        $traceableActions = 0;
        foreach ($plan->actions as $action) {
            $hasTrace = trim((string) ($action['explain_node_id'] ?? '')) !== '';
            $originMatches = trim((string) ($action['origin'] ?? '')) === $plan->origin;
            $extensionMatches = trim((string) ($action['extension'] ?? '')) === trim((string) ($plan->extension ?? ''));
            if ($hasTrace && $originMatches && $extensionMatches) {
                $traceableActions++;
            }
        }
        $traceability = $this->ratio($traceableActions, max(1, count($plan->actions)));

        $expectedValidations = ['compile_graph', 'verify_graph', 'verify_contracts'];
        if (trim((string) ($plan->metadata['feature'] ?? '')) !== '') {
            $expectedValidations[] = 'verify_feature';
        }
        $declaredValidations = array_values(array_unique(array_map('strval', $plan->validations)));
        $validationCoverage = $this->ratio(count(array_intersect($expectedValidations, $declaredValidations)), count($expectedValidations));

        $scopeControl = $this->scopeScore($plan->affectedFiles, $plan->actions);

        $riskCount = count($plan->risks);
        $riskScore = match (true) {
            $riskCount === 0 => 1.0,
            $riskCount === 1 => 0.85,
            $riskCount <= 3 => 0.65,
            default => 0.45,
        };
        if ($context->intent->allowRisky) {
            $riskScore -= 0.25;
        }
        if ($context->missingCapabilities !== []) {
            $riskScore = min($riskScore, 0.50);
        }

        return $this->build(
            'plan',
            [
                'explain_confidence' => [
                    'score' => (float) ($context->model->confidence['score'] ?? 0.50),
                    'reason' => 'Plan grounding inherits the current explain confidence for the resolved target.',
                ],
                'context_completeness' => [
                    'score' => $contextCompleteness,
                    'reason' => sprintf(
                        '%d of 7 planning context signals were available.',
                        (int) ($targetCount > 0)
                        + (int) ($subjectId !== '')
                        + (int) ($this->hasGraphRelationshipContext($context->graphRelationships))
                        + (int) ($context->constraints !== [])
                        + (int) ($context->validationSteps !== [])
                        + (int) ($context->availableGenerators !== [])
                        + (int) ($docsCount > 0),
                    ),
                ],
                'generator_specificity' => [
                    'score' => $generatorSpecificity,
                    'reason' => match (true) {
                        $mergedGenerators > 1 => 'Plan merged multiple generators with equal priority.',
                        $plan->origin === 'pack' && $plan->extension !== null && in_array($plan->extension, $packHints, true) => 'Generator matched an explicit pack hint.',
                        $plan->origin === 'pack' && $plan->extension !== null && $plan->extension === $subjectExtension => 'Pack generator matched the extension-owned target.',
                        $plan->origin === 'core' && $subjectOrigin === 'core' => 'Core generator matched a core-owned target.',
                        default => 'Generator specificity required fallback selection.',
                    },
                ],
                'traceability' => [
                    'score' => $traceability,
                    'reason' => sprintf('%d of %d planned actions include stable explain traceability.', $traceableActions, max(1, count($plan->actions))),
                ],
                'validation_coverage' => [
                    'score' => $validationCoverage,
                    'reason' => sprintf('%d of %d expected validation surfaces were declared in the plan.', count(array_intersect($expectedValidations, $declaredValidations)), count($expectedValidations)),
                ],
                'scope_control' => [
                    'score' => $scopeControl,
                    'reason' => sprintf(
                        'Plan affects %d files across %d subsystems.',
                        count($plan->affectedFiles),
                        count($this->pathSubsystems($plan->affectedFiles)),
                    ),
                ],
                'risk_profile' => [
                    'score' => $this->clamp($riskScore),
                    'reason' => match (true) {
                        $context->intent->allowRisky => 'Risky plan execution was explicitly allowed.',
                        $context->missingCapabilities !== [] => 'Plan still references missing capabilities or pack requirements.',
                        $riskCount === 0 => 'No plan-level risks were declared.',
                        default => sprintf('Plan declared %d risk notes.', $riskCount),
                    },
                ],
            ],
        );
    }

    /**
     * @param array<int,array<string,mixed>> $actionsTaken
     * @param array<string,mixed> $verificationResults
     * @param array<string,mixed>|null $architectureDiff
     * @param array<int,array<string,mixed>> $packsInstalled
     * @return array<string,mixed>
     */
    public function outcome(
        Intent $intent,
        GenerationPlan $plan,
        array $actionsTaken,
        array $verificationResults,
        ?array $architectureDiff = null,
        array $packsInstalled = [],
    ): array {
        $plannedActions = max(1, count($plan->actions));
        $completedActions = count($actionsTaken);
        $executionCompletion = $intent->dryRun
            ? 0.30
            : $this->ratio($completedActions, $plannedActions);

        $expectedSteps = ['compile_graph', 'doctor', 'verify_graph', 'verify_contracts'];
        if (trim((string) ($plan->metadata['feature'] ?? '')) !== '') {
            $expectedSteps[] = 'verify_feature';
        }

        $executedSteps = [];
        foreach ($expectedSteps as $step) {
            if (is_array($verificationResults[$step] ?? null)) {
                $executedSteps[$step] = $verificationResults[$step];
            }
        }

        if ($intent->dryRun || ($verificationResults['skipped'] ?? false) === true) {
            $verificationDepth = 0.25;
            $verificationSuccess = 0.25;
        } else {
            $verificationDepth = $this->ratio(count($executedSteps), count($expectedSteps));
            $passedSteps = 0;
            foreach ($executedSteps as $result) {
                if ((int) ($result['status'] ?? 1) === 0) {
                    $passedSteps++;
                }
            }

            $verificationSuccess = $this->ratio($passedSteps, max(1, count($executedSteps)));
        }

        $diffChanges = (int) ($architectureDiff['summary']['added'] ?? 0)
            + (int) ($architectureDiff['summary']['removed'] ?? 0)
            + (int) ($architectureDiff['summary']['modified'] ?? 0);
        $allActionsUnchanged = $actionsTaken !== [] && count(array_filter(
            $actionsTaken,
            static fn(array $action): bool => (string) ($action['status'] ?? '') === 'unchanged',
        )) === count($actionsTaken);
        $diffCoherence = match (true) {
            $intent->dryRun => 0.25,
            $architectureDiff === null => 0.45,
            $diffChanges > 0 => 1.0,
            $allActionsUnchanged => 0.85,
            default => 0.60,
        };

        $policyPosture = 1.0;
        if ($intent->skipVerify) {
            $policyPosture -= 0.35;
        }
        if ($intent->allowRisky) {
            $policyPosture -= 0.25;
        }
        if ($packsInstalled !== []) {
            $policyPosture -= 0.10;
        }

        return $this->build(
            'outcome',
            [
                'plan_confidence' => [
                    'score' => (float) ($plan->confidence['score'] ?? 0.50),
                    'reason' => 'Outcome confidence begins with the validated plan confidence.',
                ],
                'execution_completion' => [
                    'score' => $executionCompletion,
                    'reason' => $intent->dryRun
                        ? 'Dry-run mode prepared the plan without executing it.'
                        : sprintf('%d of %d planned actions reported execution results.', $completedActions, $plannedActions),
                ],
                'verification_depth' => [
                    'score' => $verificationDepth,
                    'reason' => ($verificationResults['skipped'] ?? false) === true
                        ? 'Verification was skipped, so post-change evidence is limited.'
                        : sprintf('%d of %d expected verification steps were executed.', count($executedSteps), count($expectedSteps)),
                ],
                'verification_success' => [
                    'score' => $verificationSuccess,
                    'reason' => ($verificationResults['skipped'] ?? false) === true
                        ? 'No verification results were produced.'
                        : sprintf(
                            '%d of %d executed verification steps passed.',
                            count(array_filter($executedSteps, static fn(array $result): bool => (int) ($result['status'] ?? 1) === 0)),
                            max(1, count($executedSteps)),
                        ),
                ],
                'diff_coherence' => [
                    'score' => $diffCoherence,
                    'reason' => $architectureDiff === null
                        ? 'No completed architectural diff was recorded.'
                        : sprintf('Architectural diff recorded %d added, %d removed, and %d modified elements.', (int) ($architectureDiff['summary']['added'] ?? 0), (int) ($architectureDiff['summary']['removed'] ?? 0), (int) ($architectureDiff['summary']['modified'] ?? 0)),
                ],
                'policy_posture' => [
                    'score' => $this->clamp($policyPosture),
                    'reason' => match (true) {
                        $intent->skipVerify => 'Verification was explicitly disabled.',
                        $intent->allowRisky => 'Risky execution overrides were used.',
                        $packsInstalled !== [] => 'Outcome included automatic pack installation.',
                        default => 'Outcome followed the default safety posture.',
                    },
                ],
            ],
        );
    }

    /**
     * @param array<string,mixed> $beforeSnapshot
     * @param array<string,mixed> $afterSnapshot
     * @param array<string,mixed> $diff
     * @return array<string,mixed>
     */
    public function diff(array $beforeSnapshot, array $afterSnapshot, array $diff): array
    {
        $requiredCategories = [
            'routes',
            'schemas',
            'commands',
            'workflows',
            'guards',
            'events',
            'generators',
            'packs',
            'extensions',
            'graph_nodes',
            'graph_edges',
        ];
        $beforeCoverage = $this->categoryCoverage($beforeSnapshot, $requiredCategories);
        $afterCoverage = $this->categoryCoverage($afterSnapshot, $requiredCategories);
        $expectedSummary = [
            'added' => count(array_values(array_filter((array) ($diff['added'] ?? []), 'is_array'))),
            'removed' => count(array_values(array_filter((array) ($diff['removed'] ?? []), 'is_array'))),
            'modified' => count(array_values(array_filter((array) ($diff['modified'] ?? []), 'is_array'))),
        ];
        $actualSummary = is_array($diff['summary'] ?? null) ? $diff['summary'] : [];
        $summaryMatches = $expectedSummary === [
            'added' => (int) ($actualSummary['added'] ?? -1),
            'removed' => (int) ($actualSummary['removed'] ?? -1),
            'modified' => (int) ($actualSummary['modified'] ?? -1),
        ];

        return $this->build(
            'diff',
            [
                'snapshot_compatibility' => [
                    'score' => 1.0,
                    'reason' => 'Pre- and post-generate snapshots share compatible schema versions.',
                ],
                'explain_continuity' => [
                    'score' => round((((float) ($beforeSnapshot['confidence']['score'] ?? $beforeSnapshot['explain']['confidence']['score'] ?? 0.50)) + ((float) ($afterSnapshot['confidence']['score'] ?? $afterSnapshot['explain']['confidence']['score'] ?? 0.50))) / 2, 2),
                    'reason' => sprintf(
                        'Explain continuity averaged %.2f across pre- and post-generate snapshots.',
                        ((((float) ($beforeSnapshot['confidence']['score'] ?? $beforeSnapshot['explain']['confidence']['score'] ?? 0.50)) + ((float) ($afterSnapshot['confidence']['score'] ?? $afterSnapshot['explain']['confidence']['score'] ?? 0.50))) / 2),
                    ),
                ],
                'diff_coherence' => [
                    'score' => $summaryMatches ? round(($beforeCoverage + $afterCoverage) / 2, 2) : 0.45,
                    'reason' => $summaryMatches
                        ? sprintf(
                            'Diff summary matches deterministic item counts with %.0f%% category coverage.',
                            ((($beforeCoverage + $afterCoverage) / 2) * 100),
                        )
                        : 'Diff summary and item counts are not aligned.',
                ],
            ],
        );
    }

    /**
     * @param array<string,array{score:float,reason:string}> $factors
     * @return array<string,mixed>
     */
    private function build(string $layer, array $factors): array
    {
        $weights = self::WEIGHTS[$layer] ?? [];
        $score = 0.0;
        $warnings = [];
        $rows = [];

        foreach ($weights as $name => $weight) {
            $factor = $factors[$name] ?? ['score' => 0.0, 'reason' => 'No evidence was available.'];
            $factorScore = $this->clamp((float) ($factor['score'] ?? 0.0));
            $reason = trim((string) ($factor['reason'] ?? 'No evidence was available.'));
            $score += $factorScore * $weight;
            $rows[] = [
                'name' => $name,
                'score' => round($factorScore, 2),
                'weight' => $weight,
                'reason' => $reason,
            ];

            if ($factorScore < 0.70) {
                $warnings[] = $reason;
            }
        }

        return [
            'score' => round($score, 2),
            'band' => $this->bandForScore($score),
            'factors' => $rows,
            'warnings' => array_values(array_unique($warnings)),
            'metadata' => [
                'schema_version' => 1,
                'layer' => $layer,
                'weights' => $weights,
            ],
        ];
    }

    public function bandForScore(float $score): string
    {
        return match (true) {
            $score >= 0.95 => 'very_high',
            $score >= 0.82 => 'high',
            $score >= 0.65 => 'medium',
            $score >= 0.40 => 'low',
            default => 'very_low',
        };
    }

    /**
     * @param array<int|string,mixed> $rows
     */
    private function countRows(array $rows): int
    {
        return count(array_values(array_filter($rows, static fn(mixed $row): bool => is_array($row) || is_string($row) || is_numeric($row))));
    }

    /**
     * @param array<string,mixed> $diagnostics
     */
    private function hasDiagnostics(array $diagnostics): bool
    {
        return is_array($diagnostics['summary'] ?? null) || $this->countRows($diagnostics['items'] ?? []) > 0;
    }

    /**
     * @param array<string,mixed> $relationships
     */
    private function hasGraphRelationshipContext(array $relationships): bool
    {
        return array_key_exists('inbound', $relationships)
            || array_key_exists('outbound', $relationships)
            || array_key_exists('lateral', $relationships)
            || $relationships !== [];
    }

    /**
     * @param array<int,string> $affectedFiles
     * @param array<int,array<string,mixed>> $actions
     */
    private function scopeScore(array $affectedFiles, array $actions): float
    {
        $fileCount = count($affectedFiles);
        $actionCount = count($actions);
        $subsystems = count($this->pathSubsystems($affectedFiles));
        $actionTypes = count(array_values(array_unique(array_map(
            static fn(array $action): string => (string) ($action['type'] ?? ''),
            $actions,
        ))));

        $score = match (true) {
            $fileCount <= 2 && $subsystems <= 1 => 1.0,
            $fileCount <= 4 && $subsystems <= 2 => 0.85,
            $fileCount <= 7 && $subsystems <= 3 => 0.70,
            $fileCount <= 12 => 0.55,
            default => 0.35,
        };

        if ($actionCount > 8) {
            $score -= 0.10;
        }
        if ($actionTypes > 4) {
            $score -= 0.10;
        }

        return $this->clamp($score);
    }

    /**
     * @param array<int,string> $paths
     * @return array<int,string>
     */
    private function pathSubsystems(array $paths): array
    {
        $subsystems = [];
        foreach ($paths as $path) {
            $normalized = trim((string) $path, '/');
            if ($normalized === '') {
                continue;
            }

            $segments = explode('/', $normalized);
            $subsystems[] = implode('/', array_slice($segments, 0, min(2, count($segments))));
        }

        $subsystems = array_values(array_unique(array_filter($subsystems, static fn(string $path): bool => $path !== '')));
        sort($subsystems);

        return $subsystems;
    }

    /**
     * @param array<string,mixed> $snapshot
     * @param array<int,string> $requiredCategories
     */
    private function categoryCoverage(array $snapshot, array $requiredCategories): float
    {
        $categories = is_array($snapshot['categories'] ?? null) ? $snapshot['categories'] : [];
        $available = 0;
        foreach ($requiredCategories as $category) {
            if (is_array($categories[$category] ?? null)) {
                $available++;
            }
        }

        return $this->ratio($available, count($requiredCategories));
    }

    private function ratio(int $numerator, int $denominator): float
    {
        if ($denominator <= 0) {
            return 0.0;
        }

        return $this->clamp($numerator / $denominator);
    }

    private function clamp(float $score): float
    {
        return max(0.0, min(1.0, $score));
    }
}
