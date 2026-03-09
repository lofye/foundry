<?php
declare(strict_types=1);

namespace Foundry\Compiler\Analysis\Analyzers;

use Foundry\Compiler\Analysis\AnalyzerContext;
use Foundry\Compiler\Analysis\GraphAnalyzer;
use Foundry\Compiler\ApplicationGraph;
use Foundry\Compiler\Diagnostics\DiagnosticBag;

final class TestCoverageAnalyzer implements GraphAnalyzer
{
    public function id(): string
    {
        return 'test_coverage';
    }

    public function description(): string
    {
        return 'Checks required feature tests against available test files.';
    }

    /**
     * @return array<string,mixed>
     */
    public function analyze(ApplicationGraph $graph, AnalyzerContext $context, DiagnosticBag $diagnostics): array
    {
        $missingIntegration = [];
        $missingKinds = [];

        foreach ($graph->nodesByType('feature') as $featureNode) {
            $payload = $featureNode->payload();
            $feature = (string) ($payload['feature'] ?? '');
            if ($feature === '' || !$context->includesFeature($feature)) {
                continue;
            }

            $required = array_values(array_filter(array_map('strval', (array) ($payload['tests']['required'] ?? []))));
            sort($required);
            if ($required === []) {
                continue;
            }

            $files = array_values(array_filter(array_map('strval', (array) ($payload['tests']['files'] ?? []))));
            sort($files);

            if ($files === []) {
                $missingIntegration[] = $feature;
                $diagnostics->warning(
                    code: 'FDY9010_FEATURE_TESTS_MISSING',
                    category: 'tests',
                    message: sprintf('Feature %s has required tests but no test files.', $feature),
                    nodeId: $featureNode->id(),
                    suggestedFix: 'Generate and commit required tests for this feature.',
                    pass: 'doctor.' . $this->id(),
                );
            }

            foreach ($required as $kind) {
                $expected = $feature . '_' . $kind . '_test.php';
                $matched = false;
                foreach ($files as $file) {
                    if (str_ends_with($file, $expected)) {
                        $matched = true;
                        break;
                    }
                }

                if ($matched) {
                    continue;
                }

                $missingKinds[] = [
                    'feature' => $feature,
                    'kind' => $kind,
                    'expected' => $expected,
                ];

                $diagnostics->warning(
                    code: 'FDY9011_FEATURE_TEST_KIND_MISSING',
                    category: 'tests',
                    message: sprintf('Feature %s lacks required %s test.', $feature, $kind),
                    nodeId: $featureNode->id(),
                    suggestedFix: sprintf('Add %s under app/features/%s/tests/.', $expected, $feature),
                    pass: 'doctor.' . $this->id(),
                );
            }
        }

        sort($missingIntegration);
        $missingIntegration = array_values(array_unique($missingIntegration));
        usort(
            $missingKinds,
            static fn (array $a, array $b): int => strcmp(
                (string) ($a['feature'] ?? '') . ':' . (string) ($a['kind'] ?? ''),
                (string) ($b['feature'] ?? '') . ':' . (string) ($b['kind'] ?? ''),
            ),
        );

        return [
            'features_missing_tests' => $missingIntegration,
            'missing_required_kinds' => $missingKinds,
        ];
    }
}

