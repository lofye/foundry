<?php

declare(strict_types=1);

namespace Foundry\Verification;

use Foundry\Compiler\CompileOptions;
use Foundry\Compiler\GraphCompiler;

final class BillingVerifier
{
    public function __construct(private readonly GraphCompiler $compiler) {}

    public function verify(?string $provider = null): VerificationResult
    {
        $graph = $this->compiler->compile(new CompileOptions())->graph;

        $errors = [];
        $warnings = [];

        $nodes = $provider === null || $provider === ''
            ? $graph->nodesByType('billing')
            : ['billing:' . $provider => $graph->node('billing:' . $provider)];

        if ($provider !== null && $provider !== '' && !isset($nodes['billing:' . $provider])) {
            $errors[] = 'Billing provider not found in compiled graph: ' . $provider;
        }

        foreach ($nodes as $node) {
            if (!$node instanceof \Foundry\Compiler\IR\GraphNode) {
                continue;
            }
            $payload = $node->payload();
            $name = (string) ($payload['provider'] ?? '');
            if ($name === '') {
                continue;
            }

            if (!in_array($name, ['stripe'], true)) {
                $errors[] = sprintf('Billing provider %s is unsupported.', $name);
            }

            $plans = is_array($payload['plans'] ?? null) ? $payload['plans'] : [];
            if ($plans === []) {
                $errors[] = sprintf('Billing provider %s defines no plans.', $name);
            }

            foreach ($plans as $planKey => $plan) {
                if (!is_array($plan)) {
                    continue;
                }
                if ((string) ($plan['price_id'] ?? '') === '') {
                    $errors[] = sprintf('Billing provider %s plan %s is missing price_id.', $name, (string) $planKey);
                }
            }
        }

        sort($errors);
        sort($warnings);

        return new VerificationResult($errors === [], $errors, $warnings);
    }
}
