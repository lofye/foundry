<?php
declare(strict_types=1);

namespace Foundry\Pipeline;

use Foundry\Compiler\Diagnostics\DiagnosticBag;

final class PipelineDefinitionResolver
{
    /**
     * @return array<int,string>
     */
    public static function defaultStages(): array
    {
        return [
            'request_received',
            'routing',
            'before_auth',
            'auth',
            'before_validation',
            'validation',
            'before_action',
            'action',
            'after_action',
            'response_serialization',
            'response_send',
        ];
    }

    /**
     * @param array<int,PipelineStageDefinition> $stageDefinitions
     * @return array{ordered_stages:array<int,string>,definitions:array<string,PipelineStageDefinition>}
     */
    public function resolve(array $stageDefinitions, ?DiagnosticBag $diagnostics = null): array
    {
        $definitions = [];
        $defaultStages = self::defaultStages();
        foreach ($defaultStages as $index => $stage) {
            $definitions[$stage] = new PipelineStageDefinition(
                name: $stage,
                afterStage: $index > 0 ? $defaultStages[$index - 1] : null,
                beforeStage: null,
                priority: 10 + $index,
                extension: 'core',
            );
        }

        foreach ($stageDefinitions as $definition) {
            if (!$definition instanceof PipelineStageDefinition) {
                continue;
            }

            $name = trim($definition->name);
            if ($name === '') {
                continue;
            }

            if (isset($definitions[$name])) {
                $diagnostics?->warning(
                    code: 'FDY8004_NON_DETERMINISTIC_PIPELINE_ORDER',
                    category: 'pipeline',
                    message: sprintf('Duplicate pipeline stage declaration for %s; ignoring duplicate.', $name),
                    pass: 'pipeline.resolve',
                );
                continue;
            }

            $definitions[$name] = $definition;
        }

        $this->emitAmbiguityWarnings($definitions, $diagnostics);

        $adjacency = [];
        $inDegree = [];
        foreach ($definitions as $name => $_definition) {
            $adjacency[$name] = [];
            $inDegree[$name] = 0;
        }

        for ($i = 0; $i < count($defaultStages) - 1; $i++) {
            $this->addEdge($defaultStages[$i], $defaultStages[$i + 1], $adjacency, $inDegree);
        }

        foreach ($definitions as $name => $definition) {
            if (in_array($name, $defaultStages, true)) {
                continue;
            }

            $after = $definition->afterStage;
            $before = $definition->beforeStage;

            if (is_string($after) && $after !== '') {
                if (!isset($definitions[$after])) {
                    $diagnostics?->error(
                        code: 'FDY8002_INTERCEPTOR_STAGE_CONFLICT',
                        category: 'pipeline',
                        message: sprintf('Pipeline stage %s references unknown after-stage %s.', $name, $after),
                        pass: 'pipeline.resolve',
                    );
                } else {
                    $this->addEdge($after, $name, $adjacency, $inDegree);
                }
            }

            if (is_string($before) && $before !== '') {
                if (!isset($definitions[$before])) {
                    $diagnostics?->error(
                        code: 'FDY8002_INTERCEPTOR_STAGE_CONFLICT',
                        category: 'pipeline',
                        message: sprintf('Pipeline stage %s references unknown before-stage %s.', $name, $before),
                        pass: 'pipeline.resolve',
                    );
                } else {
                    $this->addEdge($name, $before, $adjacency, $inDegree);
                }
            }

            if (($after === null || $after === '') && ($before === null || $before === '')) {
                $this->addEdge($defaultStages[count($defaultStages) - 1], $name, $adjacency, $inDegree);
            }
        }

        $ordered = $this->topologicalSort($definitions, $adjacency, $inDegree);
        if ($ordered === null) {
            $diagnostics?->error(
                code: 'FDY8004_NON_DETERMINISTIC_PIPELINE_ORDER',
                category: 'pipeline',
                message: 'Pipeline stage ordering contains a cycle; falling back to deterministic lexical order.',
                pass: 'pipeline.resolve',
            );

            $ordered = $defaultStages;
            $extras = array_values(array_filter(array_keys($definitions), static fn (string $name): bool => !in_array($name, $defaultStages, true)));
            usort(
                $extras,
                fn (string $a, string $b): int => ($definitions[$a]->priority <=> $definitions[$b]->priority)
                    ?: strcmp($a, $b),
            );
            $ordered = array_merge($ordered, $extras);
        }

        return [
            'ordered_stages' => $ordered,
            'definitions' => $definitions,
        ];
    }

    /**
     * @param array<string,PipelineStageDefinition> $definitions
     */
    private function emitAmbiguityWarnings(array $definitions, ?DiagnosticBag $diagnostics): void
    {
        $groups = [];
        foreach ($definitions as $definition) {
            if ($definition->extension === 'core') {
                continue;
            }

            $key = (string) ($definition->afterStage ?? '') . '|' . (string) ($definition->beforeStage ?? '') . '|' . $definition->priority;
            $groups[$key] ??= [];
            $groups[$key][] = $definition->name;
        }

        foreach ($groups as $names) {
            $names = array_values(array_unique(array_map('strval', $names)));
            sort($names);
            if (count($names) <= 1) {
                continue;
            }

            $diagnostics?->warning(
                code: 'FDY8004_NON_DETERMINISTIC_PIPELINE_ORDER',
                category: 'pipeline',
                message: 'Multiple extension stages share the same ordering constraints: ' . implode(', ', $names) . '.',
                pass: 'pipeline.resolve',
            );
        }
    }

    /**
     * @param array<string,array<int,string>> $adjacency
     * @param array<string,int> $inDegree
     */
    private function addEdge(string $from, string $to, array &$adjacency, array &$inDegree): void
    {
        if ($from === $to) {
            return;
        }

        $adjacency[$from] ??= [];
        if (in_array($to, $adjacency[$from], true)) {
            return;
        }

        $adjacency[$from][] = $to;
        sort($adjacency[$from]);
        $inDegree[$to] = ($inDegree[$to] ?? 0) + 1;
    }

    /**
     * @param array<string,PipelineStageDefinition> $definitions
     * @param array<string,array<int,string>> $adjacency
     * @param array<string,int> $inDegree
     * @return array<int,string>|null
     */
    private function topologicalSort(array $definitions, array $adjacency, array $inDegree): ?array
    {
        $queue = [];
        foreach ($inDegree as $name => $degree) {
            if ($degree === 0) {
                $queue[] = $name;
            }
        }

        $order = [];
        while ($queue !== []) {
            usort(
                $queue,
                fn (string $a, string $b): int => ($definitions[$a]->priority <=> $definitions[$b]->priority)
                    ?: strcmp($a, $b),
            );
            $current = array_shift($queue);
            if (!is_string($current)) {
                continue;
            }

            $order[] = $current;

            foreach ($adjacency[$current] ?? [] as $next) {
                $inDegree[$next]--;
                if ($inDegree[$next] === 0) {
                    $queue[] = $next;
                }
            }
        }

        if (count($order) !== count($definitions)) {
            return null;
        }

        return $order;
    }
}

