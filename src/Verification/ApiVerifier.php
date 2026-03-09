<?php
declare(strict_types=1);

namespace Foundry\Verification;

use Foundry\Compiler\CompileOptions;
use Foundry\Compiler\GraphCompiler;

final class ApiVerifier
{
    public function __construct(private readonly GraphCompiler $compiler)
    {
    }

    public function verify(?string $resource = null): VerificationResult
    {
        $result = $this->compiler->compile(new CompileOptions());
        $graph = $result->graph;

        $errors = [];
        $warnings = [];

        $apiResourceNodes = $resource === null || $resource === ''
            ? $graph->nodesByType('api_resource')
            : ['api_resource:' . $resource => $graph->node('api_resource:' . $resource)];

        if ($resource !== null && $resource !== '' && !isset($apiResourceNodes['api_resource:' . $resource])) {
            $errors[] = 'API resource not found in compiled graph: ' . $resource;
        }

        foreach ($apiResourceNodes as $node) {
            if (!$node instanceof \Foundry\Compiler\IR\GraphNode) {
                continue;
            }

            $payload = $node->payload();
            $resourceName = (string) ($payload['resource'] ?? '');
            if ($resourceName === '') {
                continue;
            }

            $featureMap = is_array($payload['feature_map'] ?? null) ? $payload['feature_map'] : [];
            if ($featureMap === []) {
                $warnings[] = sprintf('API resource %s has empty feature map.', $resourceName);
                continue;
            }

            foreach ($featureMap as $operation => $featureName) {
                $feature = (string) $featureName;
                if ($feature === '') {
                    $errors[] = sprintf('API resource %s has empty feature name for operation %s.', $resourceName, (string) $operation);
                    continue;
                }

                $featureNode = $graph->node('feature:' . $feature);
                if ($featureNode === null) {
                    $errors[] = sprintf('API resource %s missing feature %s.', $resourceName, $feature);
                    continue;
                }

                $featurePayload = $featureNode->payload();
                $route = is_array($featurePayload['route'] ?? null) ? $featurePayload['route'] : [];
                $path = (string) ($route['path'] ?? '');
                if (!str_starts_with($path, '/api')) {
                    $errors[] = sprintf('API feature %s route must start with /api (got %s).', $feature, $path);
                }

                $inputPath = (string) ($featurePayload['input_schema_path'] ?? '');
                $outputPath = (string) ($featurePayload['output_schema_path'] ?? '');
                if ($inputPath === '' || $outputPath === '') {
                    $errors[] = sprintf('API feature %s must define input and output schemas.', $feature);
                }
            }
        }

        sort($errors);
        sort($warnings);

        return new VerificationResult($errors === [], $errors, $warnings);
    }
}
