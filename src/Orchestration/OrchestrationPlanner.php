<?php

declare(strict_types=1);

namespace Foundry\Orchestration;

use Foundry\Support\FoundryError;

final class OrchestrationPlanner
{
    /**
     * @param array<int,array<string,mixed>> $steps
     * @return array<int,string>
     */
    public function topologicalOrder(array $steps): array
    {
        $nodes = [];
        foreach ($steps as $step) {
            if (!is_array($step)) {
                continue;
            }

            $name = (string) ($step['name'] ?? '');
            if ($name === '') {
                continue;
            }

            $nodes[$name] = [
                'depends_on' => array_values(array_map('strval', (array) ($step['depends_on'] ?? []))),
            ];
        }

        $incoming = [];
        $dependents = [];
        foreach ($nodes as $name => $meta) {
            $incoming[$name] = 0;
            $dependents[$name] = [];
        }

        foreach ($nodes as $name => $meta) {
            foreach ($meta['depends_on'] as $dependency) {
                if (!isset($nodes[$dependency])) {
                    throw new FoundryError('ORCHESTRATION_DEPENDENCY_UNKNOWN', 'orchestration', ['step' => $name, 'dependency' => $dependency], 'Orchestration dependency references unknown step.');
                }

                $incoming[$name]++;
                $dependents[$dependency][] = $name;
            }
        }

        $queue = [];
        foreach ($incoming as $name => $count) {
            if ($count === 0) {
                $queue[] = $name;
            }
        }
        sort($queue);

        $order = [];
        while ($queue !== []) {
            $name = array_shift($queue);
            if (!is_string($name)) {
                continue;
            }

            $order[] = $name;
            foreach ($dependents[$name] as $dependent) {
                $incoming[$dependent]--;
                if ($incoming[$dependent] === 0) {
                    $queue[] = $dependent;
                }
            }
            sort($queue);
        }

        if (count($order) !== count($nodes)) {
            throw new FoundryError('ORCHESTRATION_CYCLE', 'orchestration', [], 'Orchestration contains a dependency cycle.');
        }

        return $order;
    }
}
