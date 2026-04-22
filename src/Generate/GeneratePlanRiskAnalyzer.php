<?php

declare(strict_types=1);

namespace Foundry\Generate;

final class GeneratePlanRiskAnalyzer
{
    /**
     * @return array{level:string,reasons:list<string>,risky_action_indexes:list<int>,risky_paths:list<string>}
     */
    public function analyze(GenerationPlan $plan): array
    {
        $level = 'LOW';
        $reasons = [];
        $riskyActionIndexes = [];
        $riskyPaths = [];

        foreach ($plan->actions as $index => $action) {
            $type = strtolower(trim((string) ($action['type'] ?? '')));
            $path = trim((string) ($action['path'] ?? ''));
            $summary = strtolower(trim((string) ($action['summary'] ?? '')));
            $keywords = strtolower($path . ' ' . $summary . ' ' . implode(' ', $plan->risks));

            $isDelete = $type === 'delete_file';
            $isSchema = $type === 'update_schema' || str_contains($keywords, 'schema');
            $isContract = str_contains($keywords, 'contract');
            $isModify = in_array($type, ['update_file', 'update_docs', 'update_graph'], true);

            if ($isDelete || $isSchema || $isContract) {
                $level = 'HIGH';
                $riskyActionIndexes[] = (int) $index;
                if ($path !== '') {
                    $riskyPaths[] = $path;
                }

                if ($isDelete) {
                    $reasons[] = 'Plan includes file deletion.';
                }

                if ($isSchema) {
                    $reasons[] = 'Plan touches schema-shaped artifacts.';
                }

                if ($isContract) {
                    $reasons[] = 'Plan may affect contract-facing behavior.';
                }

                continue;
            }

            if ($isModify && $level !== 'HIGH') {
                $level = 'MEDIUM';
                $reasons[] = 'Plan modifies existing files.';
            }
        }

        if ($reasons === []) {
            $reasons[] = 'Plan is additive only.';
        }

        $reasons = array_values(array_unique(array_map('strval', $reasons)));
        $riskyPaths = array_values(array_unique(array_map('strval', $riskyPaths)));
        sort($reasons);
        sort($riskyPaths);

        return [
            'level' => $level,
            'reasons' => $reasons,
            'risky_action_indexes' => array_values(array_unique($riskyActionIndexes)),
            'risky_paths' => $riskyPaths,
        ];
    }
}
