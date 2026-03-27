<?php

declare(strict_types=1);

namespace Foundry\Verification;

use Foundry\Compiler\CompileOptions;
use Foundry\Compiler\GraphCompiler;

final class PoliciesVerifier
{
    public function __construct(private readonly GraphCompiler $compiler) {}

    public function verify(?string $policy = null): VerificationResult
    {
        $graph = $this->compiler->compile(new CompileOptions())->graph;

        $errors = [];
        $warnings = [];

        $roleSet = [];
        foreach ($graph->nodesByType('role') as $roleNode) {
            $name = (string) ($roleNode->payload()['role'] ?? '');
            if ($name !== '') {
                $roleSet[$name] = true;
            }
        }

        $nodes = $policy === null || $policy === ''
            ? $graph->nodesByType('policy')
            : ['policy:' . $policy => $graph->node('policy:' . $policy)];

        if ($policy !== null && $policy !== '' && !isset($nodes['policy:' . $policy])) {
            $errors[] = 'Policy not found in compiled graph: ' . $policy;
        }

        foreach ($nodes as $node) {
            if (!$node instanceof \Foundry\Compiler\IR\GraphNode) {
                continue;
            }
            $payload = $node->payload();
            $name = (string) ($payload['policy'] ?? '');
            $rules = is_array($payload['rules'] ?? null) ? $payload['rules'] : [];

            if ($rules === []) {
                $warnings[] = sprintf('Policy %s has no role rules.', $name);
                continue;
            }

            foreach ($rules as $roleName => $permissions) {
                if (!is_string($roleName) || $roleName === '') {
                    continue;
                }
                if (!isset($roleSet[$roleName])) {
                    $errors[] = sprintf('Policy %s references missing role %s.', $name, $roleName);
                }

                $permissionList = array_values(array_map('strval', (array) $permissions));
                if ($permissionList === []) {
                    $warnings[] = sprintf('Policy %s role %s has empty permission list.', $name, $roleName);
                }
            }
        }

        sort($errors);
        sort($warnings);

        return new VerificationResult($errors === [], $errors, $warnings);
    }
}
