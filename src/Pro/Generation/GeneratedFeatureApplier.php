<?php
declare(strict_types=1);

namespace Foundry\Pro\Generation;

use Foundry\Generation\FeatureGenerator;
use Foundry\Generation\WorkflowGenerator;
use Foundry\Support\Paths;
use Foundry\Support\Yaml;

final class GeneratedFeatureApplier
{
    public function __construct(
        private readonly Paths $paths,
        private readonly FeatureGenerator $featureGenerator,
        private readonly WorkflowGenerator $workflowGenerator,
    ) {
    }

    /**
     * @param array<string,mixed> $plan
     * @return array<string,mixed>
     */
    public function apply(array $plan, bool $force = false): array
    {
        $files = [];
        $artifacts = [];

        $featureDefinition = is_array($plan['feature'] ?? null) ? $plan['feature'] : [];
        $featureFiles = $this->featureGenerator->generateFromArray($featureDefinition, $force);
        $artifacts['feature'] = [
            'feature' => (string) ($featureDefinition['feature'] ?? ''),
            'files' => $featureFiles,
        ];
        $files = array_merge($files, $featureFiles);

        if (is_array($plan['workflow'] ?? null)) {
            $workflow = $plan['workflow'];
            $tempPath = $this->writeWorkflowDefinition($workflow);
            $workflowResult = $this->workflowGenerator->generate((string) ($workflow['name'] ?? ''), $tempPath, $force);
            @unlink($tempPath);

            $artifacts['workflow'] = $workflowResult;
            $files = array_merge($files, array_values(array_map('strval', (array) ($workflowResult['files'] ?? []))));
        }

        $files = array_values(array_unique(array_filter(array_map('strval', $files))));
        sort($files);

        return [
            'files' => $files,
            'artifacts' => $artifacts,
        ];
    }

    /**
     * @param array<string,mixed> $plan
     * @return array<int,string>
     */
    public function predictedFiles(array $plan): array
    {
        $files = [];

        $featureDefinition = is_array($plan['feature'] ?? null) ? $plan['feature'] : [];
        $feature = trim((string) ($featureDefinition['feature'] ?? ''));
        if ($feature !== '') {
            $files = array_merge($files, $this->featureFiles($feature));
        }

        if (is_array($plan['workflow'] ?? null)) {
            $workflow = $plan['workflow'];
            $workflowName = trim((string) ($workflow['name'] ?? ''));
            $resource = trim((string) ($workflow['definition']['resource'] ?? ''));
            if ($workflowName !== '') {
                $files[] = $this->paths->join('app/definitions/workflows/' . $workflowName . '.workflow.yaml');
            }

            if ($resource !== '') {
                $files = array_merge($files, $this->featureFiles('transition_' . $this->singularize($resource) . '_workflow'));
            }
        }

        $files = array_values(array_unique(array_filter(array_map('strval', $files))));
        sort($files);

        return $files;
    }

    /**
     * @param array<string,mixed> $workflow
     */
    private function writeWorkflowDefinition(array $workflow): string
    {
        $dir = $this->paths->join('storage/tmp/foundry-ai');
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }

        $path = $dir . '/' . (string) ($workflow['name'] ?? 'workflow') . '.workflow.yaml';
        $definition = is_array($workflow['definition'] ?? null) ? $workflow['definition'] : [];
        file_put_contents($path, Yaml::dump(array_merge(['version' => 1], $definition)));

        return $path;
    }

    /**
     * @return array<int,string>
     */
    private function featureFiles(string $feature): array
    {
        $base = $this->paths->join('app/features/' . $feature);

        return [
            $base . '/feature.yaml',
            $base . '/input.schema.json',
            $base . '/output.schema.json',
            $base . '/action.php',
            $base . '/queries.sql',
            $base . '/permissions.yaml',
            $base . '/cache.yaml',
            $base . '/events.yaml',
            $base . '/jobs.yaml',
            $base . '/prompts.md',
            $base . '/context.manifest.json',
            $base . '/tests/' . $feature . '_contract_test.php',
            $base . '/tests/' . $feature . '_feature_test.php',
            $base . '/tests/' . $feature . '_auth_test.php',
        ];
    }

    private function singularize(string $value): string
    {
        if (str_ends_with($value, 'ies') && strlen($value) > 3) {
            return substr($value, 0, -3) . 'y';
        }

        if (str_ends_with($value, 's') && strlen($value) > 1) {
            return substr($value, 0, -1);
        }

        return $value;
    }
}
