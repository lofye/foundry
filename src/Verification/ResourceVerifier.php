<?php
declare(strict_types=1);

namespace Foundry\Verification;

use Foundry\Compiler\CompileOptions;
use Foundry\Compiler\GraphCompiler;

final class ResourceVerifier
{
    public function __construct(private readonly GraphCompiler $compiler)
    {
    }

    public function verify(string $resource): VerificationResult
    {
        $resource = trim($resource);
        $result = $this->compiler->compile(new CompileOptions());
        $graph = $result->graph;

        $errors = [];
        $warnings = [];

        $resourceNode = $graph->node('resource:' . $resource);
        if ($resourceNode === null) {
            $errors[] = 'Resource not found in compiled graph: ' . $resource;

            return new VerificationResult(false, $errors, $warnings);
        }

        $payload = $resourceNode->payload();
        $featureMap = is_array($payload['feature_map'] ?? null) ? $payload['feature_map'] : [];

        foreach ($featureMap as $operation => $featureName) {
            $feature = (string) $featureName;
            if ($feature === '') {
                continue;
            }

            if ($graph->node('feature:' . $feature) === null) {
                $errors[] = sprintf('Missing generated feature for %s operation: %s', (string) $operation, $feature);
                continue;
            }

            if ($graph->node('execution_plan:feature:' . $feature) === null) {
                $warnings[] = sprintf('Feature has no execution plan projection: %s', $feature);
            }
        }

        $listingNode = $graph->node('listing_config:' . $resource);
        if ($listingNode === null) {
            $warnings[] = 'Listing config not found for resource: ' . $resource;
        }

        return new VerificationResult($errors === [], $errors, $warnings);
    }
}
