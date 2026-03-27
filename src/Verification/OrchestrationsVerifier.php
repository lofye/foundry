<?php

declare(strict_types=1);

namespace Foundry\Verification;

use Foundry\Compiler\CompileOptions;
use Foundry\Compiler\GraphCompiler;

final class OrchestrationsVerifier
{
    public function __construct(private readonly GraphCompiler $compiler) {}

    public function verify(?string $name = null): VerificationResult
    {
        $graph = $this->compiler->compile(new CompileOptions())->graph;

        $errors = [];
        $warnings = [];

        $nodes = $name === null || $name === ''
            ? $graph->nodesByType('orchestration')
            : ['orchestration:' . $name => $graph->node('orchestration:' . $name)];

        if ($name !== null && $name !== '' && !isset($nodes['orchestration:' . $name])) {
            $errors[] = 'Orchestration not found in compiled graph: ' . $name;
        }

        foreach ($nodes as $node) {
            if (!$node instanceof \Foundry\Compiler\IR\GraphNode) {
                continue;
            }

            $payload = $node->payload();
            $orchestration = (string) ($payload['name'] ?? '');
            $steps = is_array($payload['steps'] ?? null) ? $payload['steps'] : [];
            if ($steps === []) {
                $errors[] = sprintf('Orchestration %s has no steps.', $orchestration);
                continue;
            }

            $known = [];
            foreach ($steps as $step) {
                if (!is_array($step)) {
                    continue;
                }
                $stepName = (string) ($step['name'] ?? '');
                if ($stepName !== '') {
                    $known[$stepName] = true;
                }
                if ((string) ($step['job'] ?? '') === '') {
                    $errors[] = sprintf('Orchestration %s has a step without job.', $orchestration);
                }
            }

            foreach ($steps as $step) {
                if (!is_array($step)) {
                    continue;
                }
                $stepName = (string) ($step['name'] ?? '');
                foreach ((array) ($step['depends_on'] ?? []) as $dependency) {
                    $dep = (string) $dependency;
                    if ($dep !== '' && !isset($known[$dep])) {
                        $errors[] = sprintf('Orchestration %s step %s depends on unknown step %s.', $orchestration, $stepName, $dep);
                    }
                }
            }
        }

        sort($errors);
        sort($warnings);

        return new VerificationResult($errors === [], $errors, $warnings);
    }
}
