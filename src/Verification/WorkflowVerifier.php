<?php
declare(strict_types=1);

namespace Foundry\Verification;

use Foundry\Compiler\CompileOptions;
use Foundry\Compiler\GraphCompiler;

final class WorkflowVerifier
{
    public function __construct(private readonly GraphCompiler $compiler)
    {
    }

    public function verify(?string $name = null): VerificationResult
    {
        $graph = $this->compiler->compile(new CompileOptions())->graph;

        $errors = [];
        $warnings = [];

        $nodes = $name === null || $name === ''
            ? $graph->nodesByType('workflow')
            : ['workflow:' . $name => $graph->node('workflow:' . $name)];

        if ($name !== null && $name !== '' && !isset($nodes['workflow:' . $name])) {
            $errors[] = 'Workflow not found in compiled graph: ' . $name;
        }

        foreach ($nodes as $node) {
            if (!$node instanceof \Foundry\Compiler\IR\GraphNode) {
                continue;
            }

            $payload = $node->payload();
            $resource = (string) ($payload['resource'] ?? '');
            $states = array_values(array_map('strval', (array) ($payload['states'] ?? [])));
            $stateSet = array_flip($states);
            if ($states === []) {
                $errors[] = sprintf('Workflow %s has no states.', $resource);
                continue;
            }

            $transitions = is_array($payload['transitions'] ?? null) ? $payload['transitions'] : [];
            foreach ($transitions as $transitionName => $transition) {
                if (!is_array($transition)) {
                    continue;
                }
                foreach ((array) ($transition['from'] ?? []) as $from) {
                    $fromState = (string) $from;
                    if (!isset($stateSet[$fromState])) {
                        $errors[] = sprintf('Workflow %s transition %s has invalid from-state %s.', $resource, (string) $transitionName, $fromState);
                    }
                }
                $to = (string) ($transition['to'] ?? '');
                if ($to !== '' && !isset($stateSet[$to])) {
                    $errors[] = sprintf('Workflow %s transition %s has invalid to-state %s.', $resource, (string) $transitionName, $to);
                }
            }
        }

        sort($errors);
        sort($warnings);

        return new VerificationResult($errors === [], $errors, $warnings);
    }
}
