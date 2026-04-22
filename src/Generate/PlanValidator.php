<?php

declare(strict_types=1);

namespace Foundry\Generate;

use Foundry\Support\FoundryError;

final class PlanValidator
{
    public function validate(GenerationPlan $plan, Intent $intent, bool $forInteractiveReview = false): void
    {
        if (!in_array($plan->origin, ['core', 'pack'], true)) {
            throw new FoundryError(
                'GENERATE_PLAN_INVALID',
                'validation',
                ['origin' => $plan->origin],
                'Generation plan origin must be core or pack.',
            );
        }

        if ($plan->origin === 'pack' && ($plan->extension === null || $plan->extension === '')) {
            throw new FoundryError(
                'GENERATE_PLAN_INVALID',
                'validation',
                ['generator_id' => $plan->generatorId],
                'Pack-origin generation plans must declare their extension.',
            );
        }

        $seenPaths = [];
        foreach ($plan->actions as $action) {
            $type = (string) ($action['type'] ?? '');
            if (!in_array($type, [
                'create_file',
                'update_file',
                'delete_file',
                'register_component',
                'update_graph',
                'update_schema',
                'add_test',
                'update_docs',
            ], true)) {
                throw new FoundryError(
                    'GENERATE_PLAN_INVALID',
                    'validation',
                    ['action' => $action],
                    'Generation plan contains an unsupported action type.',
                );
            }

            $path = trim((string) ($action['path'] ?? ''));
            if ($path !== '') {
                if (isset($seenPaths[$type . ':' . $path])) {
                    throw new FoundryError(
                        'GENERATE_PLAN_INVALID',
                        'validation',
                        ['action' => $action],
                        'Generation plan contains duplicate file actions.',
                    );
                }

                $seenPaths[$type . ':' . $path] = true;
            }

            if (!isset($action['explain_node_id']) || trim((string) $action['explain_node_id']) === '') {
                throw new FoundryError(
                    'GENERATE_PLAN_INVALID',
                    'validation',
                    ['action' => $action],
                    'Generation actions must include explain traceability.',
                );
            }

            if ($type === 'delete_file' && !$intent->allowRisky && !($forInteractiveReview && $intent->interactive)) {
                throw new FoundryError(
                    'GENERATE_UNSAFE_OPERATION',
                    'validation',
                    ['action' => $action],
                    'Deleting files requires --allow-risky.',
                );
            }
        }
    }
}
