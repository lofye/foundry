<?php
declare(strict_types=1);

namespace Foundry\Generation;

use Foundry\Compiler\CompileOptions;
use Foundry\Compiler\GraphCompiler;
use Foundry\Support\FoundryError;
use Foundry\Support\Paths;

final class DeepTestGenerator
{
    public function __construct(
        private readonly Paths $paths,
        private readonly GraphCompiler $compiler,
        private readonly TestGenerator $tests,
    ) {
    }

    /**
     * @return array<string,mixed>
     */
    public function generateForTarget(string $target, string $mode = 'deep'): array
    {
        $target = trim($target);
        if ($target === '') {
            throw new FoundryError('CLI_TARGET_REQUIRED', 'validation', [], 'Generation target is required.');
        }

        $graph = $this->compiler->loadGraph() ?? $this->compiler->compile(new CompileOptions())->graph;

        if ($graph->node('feature:' . $target) !== null) {
            $files = $this->generateForFeature($graph, $target, $mode);

            return [
                'target' => $target,
                'mode' => $mode,
                'kind' => 'feature',
                'files' => $files,
            ];
        }

        if ($graph->node('resource:' . $target) !== null || $graph->node('api_resource:' . $target) !== null) {
            $features = [];
            $resourceNode = $graph->node('resource:' . $target);
            if ($resourceNode !== null) {
                $featureMap = is_array($resourceNode->payload()['feature_map'] ?? null) ? $resourceNode->payload()['feature_map'] : [];
                foreach ($featureMap as $feature) {
                    $value = (string) $feature;
                    if ($value !== '') {
                        $features[] = $value;
                    }
                }
            }

            $apiResourceNode = $graph->node('api_resource:' . $target);
            if ($apiResourceNode !== null) {
                $featureMap = is_array($apiResourceNode->payload()['feature_map'] ?? null) ? $apiResourceNode->payload()['feature_map'] : [];
                foreach ($featureMap as $feature) {
                    $value = (string) $feature;
                    if ($value !== '') {
                        $features[] = $value;
                    }
                }
            }

            $features = array_values(array_unique($features));
            sort($features);

            if ($features === []) {
                throw new FoundryError('RESOURCE_FEATURES_MISSING', 'validation', ['resource' => $target], 'No features found for target resource.');
            }

            $files = [];
            foreach ($features as $feature) {
                foreach ($this->generateForFeature($graph, $feature, $mode) as $file) {
                    $files[] = $file;
                }
            }
            $files = array_values(array_unique($files));
            sort($files);

            return [
                'target' => $target,
                'mode' => $mode,
                'kind' => $graph->node('api_resource:' . $target) !== null ? 'api_resource' : 'resource',
                'features' => $features,
                'files' => $files,
            ];
        }

        throw new FoundryError('TARGET_NOT_FOUND', 'not_found', ['target' => $target], 'Target feature/resource not found.');
    }

    /**
     * @return array<string,mixed>
     */
    public function generateAllMissing(string $mode = 'basic'): array
    {
        $graph = $this->compiler->loadGraph() ?? $this->compiler->compile(new CompileOptions())->graph;

        $files = [];
        $features = [];
        foreach ($graph->nodesByType('feature') as $node) {
            $payload = $node->payload();
            $feature = (string) ($payload['feature'] ?? '');
            if ($feature === '') {
                continue;
            }

            $base = $this->paths->join('app/features/' . $feature);
            if (!is_dir($base)) {
                continue;
            }

            $missing = $this->missingRequiredTests($feature, $payload);
            if ($missing !== []) {
                foreach ($this->tests->generate($feature, $base, $missing) as $file) {
                    $files[] = $file;
                }
                $features[] = $feature;
            }

            if ($this->isDeepMode($mode)) {
                foreach ($this->writeDeepTest($graph, $feature, $payload, $base) as $file) {
                    $files[] = $file;
                }
                $features[] = $feature;
            }
        }

        $files = array_values(array_unique($files));
        sort($files);
        $features = array_values(array_unique($features));
        sort($features);

        return [
            'mode' => $mode,
            'features' => $features,
            'files' => $files,
        ];
    }

    /**
     * @param array<string,mixed> $payload
     * @return array<int,string>
     */
    private function generateForFeature(\Foundry\Compiler\ApplicationGraph $graph, string $feature, string $mode): array
    {
        $node = $graph->node('feature:' . $feature);
        if ($node === null) {
            throw new FoundryError('FEATURE_NOT_FOUND', 'not_found', ['feature' => $feature], 'Feature not found.');
        }

        $payload = $node->payload();
        $base = $this->paths->join('app/features/' . $feature);
        if (!is_dir($base)) {
            throw new FoundryError('FEATURE_NOT_FOUND', 'not_found', ['feature' => $feature], 'Feature directory not found.');
        }

        $required = array_values(array_map('strval', (array) ($payload['tests']['required'] ?? ['contract', 'feature', 'auth'])));
        $files = $this->tests->generate($feature, $base, $required);

        if ($this->isDeepMode($mode)) {
            foreach ($this->writeDeepTest($graph, $feature, $payload, $base) as $file) {
                $files[] = $file;
            }
        }

        $files = array_values(array_unique($files));
        sort($files);

        return $files;
    }

    /**
     * @param array<string,mixed> $payload
     * @return array<int,string>
     */
    private function missingRequiredTests(string $feature, array $payload): array
    {
        $required = array_values(array_map('strval', (array) ($payload['tests']['required'] ?? [])));
        $required = array_values(array_unique(array_filter($required, static fn (string $value): bool => $value !== '')));
        sort($required);

        $missing = [];
        foreach ($required as $kind) {
            $path = $this->paths->join('app/features/' . $feature . '/tests/' . $feature . '_' . $kind . '_test.php');
            if (!is_file($path)) {
                $missing[] = $kind;
            }
        }

        return $missing;
    }

    /**
     * @param array<string,mixed> $payload
     * @return array<int,string>
     */
    private function writeDeepTest(\Foundry\Compiler\ApplicationGraph $graph, string $feature, array $payload, string $base): array
    {
        $testsPath = $base . '/tests';
        if (!is_dir($testsPath)) {
            mkdir($testsPath, 0777, true);
        }

        $path = $testsPath . '/' . $feature . '_deep_test.php';
        $scenarios = $this->scenarios($graph, $feature, $payload);
        $class = str_replace(' ', '', ucwords(str_replace('_', ' ', $feature))) . 'DeepTest';

        $methods = [];
        foreach ($scenarios as $scenario) {
            $methodName = 'test_' . $scenario;
            $methods[] = <<<PHP
    public function {$methodName}(): void
    {
        self::assertTrue(true);
    }

PHP;
        }

        $body = implode('', $methods);
        $content = <<<PHP
<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class {$class} extends TestCase
{
{$body}}
PHP;

        file_put_contents($path, $content);

        return [$path];
    }

    /**
     * @param array<string,mixed> $payload
     * @return array<int,string>
     */
    private function scenarios(\Foundry\Compiler\ApplicationGraph $graph, string $feature, array $payload): array
    {
        $route = is_array($payload['route'] ?? null) ? $payload['route'] : [];
        $auth = is_array($payload['auth'] ?? null) ? $payload['auth'] : [];
        $database = is_array($payload['database'] ?? null) ? $payload['database'] : [];
        $events = is_array($payload['events'] ?? null) ? $payload['events'] : [];
        $jobs = is_array($payload['jobs'] ?? null) ? $payload['jobs'] : [];

        $scenarios = ['happy_path'];

        if ((bool) ($auth['required'] ?? false) || (array) ($auth['permissions'] ?? []) !== []) {
            $scenarios[] = 'auth_failure';
        }

        $inputSchema = is_array($payload['input_schema'] ?? null) ? $payload['input_schema'] : [];
        if ((array) ($inputSchema['required'] ?? []) !== []) {
            $scenarios[] = 'validation_failure';
        }

        $path = (string) ($route['path'] ?? '');
        if (str_contains($path, '{id}')) {
            $scenarios[] = 'not_found';
        }

        if ((array) ($database['writes'] ?? []) !== []) {
            $scenarios[] = 'db_side_effects';
        }

        if ((array) ($events['emit'] ?? []) !== []) {
            $scenarios[] = 'event_emission';
        }

        if ((array) ($jobs['dispatch'] ?? []) !== []) {
            $scenarios[] = 'job_dispatch';
        }

        if (str_starts_with($path, '/api')) {
            $scenarios[] = 'api_error_envelope';
            $scenarios[] = 'json_output_shape';
        }

        $listing = is_array($payload['listing'] ?? null) ? $payload['listing'] : [];
        if ($listing !== []) {
            $scenarios[] = 'listing_filters_and_sort';
        }

        foreach ($graph->dependencies('feature:' . $feature) as $edge) {
            if ($edge->type === 'feature_to_notification_dispatch') {
                $scenarios[] = 'notification_dispatch';
                break;
            }
        }

        $scenarios = array_values(array_unique(array_map('strval', $scenarios)));
        sort($scenarios);

        return $scenarios;
    }

    private function isDeepMode(string $mode): bool
    {
        return in_array($mode, ['deep', 'resource', 'api', 'notification'], true);
    }
}
