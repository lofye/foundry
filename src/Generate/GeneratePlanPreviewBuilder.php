<?php

declare(strict_types=1);

namespace Foundry\Generate;

use Foundry\Generation\ContextManifestGenerator;
use Foundry\Generation\FeatureGenerator;
use Foundry\Generation\TestGenerator;
use Foundry\Support\Paths;
use Foundry\Support\Yaml;

final class GeneratePlanPreviewBuilder
{
    public function __construct(
        private readonly Paths $paths,
        private readonly GenerateUnifiedDiffRenderer $diffRenderer = new GenerateUnifiedDiffRenderer(),
    ) {}

    /**
     * @return array{files:list<array<string,mixed>>}
     */
    public function build(GenerationPlan $plan, Intent $intent): array
    {
        $afterContents = $this->afterContents($plan, $intent);
        $files = [];

        foreach ($plan->actions as $index => $action) {
            $type = trim((string) ($action['type'] ?? ''));
            $path = trim((string) ($action['path'] ?? ''));
            if ($path === '' || !$this->isFileAction($type)) {
                continue;
            }

            $absolute = $this->absolutePath($path);
            $beforeExists = is_file($absolute);
            $before = $beforeExists ? (string) (file_get_contents($absolute) ?: '') : null;
            $afterExists = array_key_exists($path, $afterContents)
                ? $afterContents[$path] !== null
                : $type !== 'delete_file' && $beforeExists;
            $after = $afterContents[$path] ?? ($type === 'delete_file' ? null : $before);
            $changeType = $this->changeType($type, $beforeExists, $afterExists);

            $files[] = [
                'action_index' => (int) $index,
                'path' => $path,
                'action_type' => $type,
                'change_type' => $changeType,
                'before_exists' => $beforeExists,
                'after_exists' => $afterExists,
                'unified_diff' => $this->diffRenderer->render($path, $before, $after),
            ];
        }

        return ['files' => $files];
    }

    /**
     * @return array<string,string|null>
     */
    public function afterContents(GenerationPlan $plan, Intent $intent): array
    {
        return $this->predictedAfterContents($plan, $intent);
    }

    private function isFileAction(string $type): bool
    {
        return in_array($type, [
            'create_file',
            'update_file',
            'delete_file',
            'add_test',
            'update_docs',
        ], true);
    }

    private function changeType(string $actionType, bool $beforeExists, bool $afterExists): string
    {
        if ($actionType === 'delete_file' || ($beforeExists && !$afterExists)) {
            return 'delete';
        }

        if (!$beforeExists && $afterExists) {
            return 'create';
        }

        return 'update';
    }

    /**
     * @return array<string,string|null>
     */
    private function predictedAfterContents(GenerationPlan $plan, Intent $intent): array
    {
        $execution = is_array($plan->metadata['execution'] ?? null) ? $plan->metadata['execution'] : [];
        $strategy = (string) ($execution['strategy'] ?? '');

        return match ($strategy) {
            'feature_definition' => $this->previewFeatureDefinition($execution, $intent),
            'modify_feature' => $this->previewModifyFeature($execution),
            'repair_feature' => $this->previewRepairFeature($execution),
            default => $this->previewFallbackDeletes($plan),
        };
    }

    /**
     * @param array<string,mixed> $execution
     * @return array<string,string|null>
     */
    private function previewFeatureDefinition(array $execution, Intent $intent): array
    {
        $definition = is_array($execution['feature_definition'] ?? null) ? $execution['feature_definition'] : [];
        if ($definition === []) {
            return [];
        }

        [$tempRoot, $tempPaths] = $this->makeTempPaths();

        try {
            (new FeatureGenerator($tempPaths))->generateFromArray($definition, $intent->allowRisky);

            return $this->readActionFilesFromTemp(
                $tempPaths,
                FeaturePlanBuilder::predictedFiles((string) ($definition['feature'] ?? ''), array_values(array_map('strval', (array) ($definition['tests']['required'] ?? [])))),
            );
        } finally {
            $this->deleteDirectory($tempRoot);
        }
    }

    /**
     * @param array<string,mixed> $execution
     * @return array<string,string|null>
     */
    private function previewModifyFeature(array $execution): array
    {
        $preview = [];
        $manifestPath = trim((string) ($execution['manifest_path'] ?? ''));
        $manifest = is_array($execution['manifest'] ?? null) ? $execution['manifest'] : [];
        $promptsPath = trim((string) ($execution['prompts_path'] ?? ''));
        $promptsContent = (string) ($execution['prompts_content'] ?? '');

        if ($manifestPath !== '' && $manifest !== []) {
            $preview[$manifestPath] = Yaml::dump($manifest);
        }

        if ($promptsPath !== '' && $promptsContent !== '') {
            $preview[$promptsPath] = $promptsContent;
        }

        return $preview;
    }

    /**
     * @param array<string,mixed> $execution
     * @return array<string,string|null>
     */
    private function previewRepairFeature(array $execution): array
    {
        $feature = trim((string) ($execution['feature'] ?? ''));
        if ($feature === '') {
            return [];
        }

        [$tempRoot, $tempPaths] = $this->makeTempPaths();

        try {
            $sourceBasePath = $this->absolutePath((string) ($execution['base_path'] ?? ('app/features/' . $feature)));
            $targetBasePath = $tempPaths->join('app/features/' . $feature);
            if (is_dir($sourceBasePath)) {
                $this->copyDirectory($sourceBasePath, $targetBasePath);
            } elseif (!is_dir($targetBasePath)) {
                mkdir($targetBasePath, 0777, true);
            }

            $missingTests = array_values(array_map('strval', (array) ($execution['missing_tests'] ?? [])));
            if ($missingTests !== []) {
                (new TestGenerator())->generate($feature, $targetBasePath, $missingTests);
            }

            if ((bool) ($execution['restore_context_manifest'] ?? false)) {
                $manifest = is_array($execution['manifest'] ?? null) ? $execution['manifest'] : [];
                (new ContextManifestGenerator($tempPaths))->write($feature, $manifest);
            }

            $paths = [];
            foreach ($missingTests as $type) {
                $paths[] = 'app/features/' . $feature . '/tests/' . $feature . '_' . $type . '_test.php';
            }

            if ((bool) ($execution['restore_context_manifest'] ?? false)) {
                $paths[] = 'app/features/' . $feature . '/context.manifest.json';
            }

            return $this->readActionFilesFromTemp($tempPaths, $paths);
        } finally {
            $this->deleteDirectory($tempRoot);
        }
    }

    /**
     * @return array<string,string|null>
     */
    private function previewFallbackDeletes(GenerationPlan $plan): array
    {
        $preview = [];

        foreach ($plan->actions as $action) {
            $type = trim((string) ($action['type'] ?? ''));
            $path = trim((string) ($action['path'] ?? ''));
            if ($type !== 'delete_file' || $path === '') {
                continue;
            }

            $preview[$path] = null;
        }

        return $preview;
    }

    /**
     * @param array<int,string> $paths
     * @return array<string,string|null>
     */
    private function readActionFilesFromTemp(Paths $tempPaths, array $paths): array
    {
        $preview = [];

        foreach ($paths as $path) {
            $absolute = $tempPaths->join($path);
            $preview[$path] = is_file($absolute) ? (string) (file_get_contents($absolute) ?: '') : null;
        }

        return $preview;
    }

    /**
     * @return array{0:string,1:Paths}
     */
    private function makeTempPaths(): array
    {
        $tempRoot = sys_get_temp_dir() . '/foundry-generate-preview-' . bin2hex(random_bytes(6));
        mkdir($tempRoot, 0777, true);

        return [$tempRoot, new Paths($tempRoot, $this->paths->frameworkRoot())];
    }

    private function absolutePath(string $path): string
    {
        return str_starts_with($path, $this->paths->root() . '/')
            ? $path
            : $this->paths->join($path);
    }

    private function copyDirectory(string $source, string $target): void
    {
        if (!is_dir($target)) {
            mkdir($target, 0777, true);
        }

        $items = scandir($source);
        if ($items === false) {
            return;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $sourcePath = $source . '/' . $item;
            $targetPath = $target . '/' . $item;

            if (is_dir($sourcePath)) {
                $this->copyDirectory($sourcePath, $targetPath);

                continue;
            }

            copy($sourcePath, $targetPath);
        }
    }

    private function deleteDirectory(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        $items = scandir($path);
        if ($items === false) {
            return;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $fullPath = $path . '/' . $item;
            if (is_dir($fullPath)) {
                $this->deleteDirectory($fullPath);

                continue;
            }

            @unlink($fullPath);
        }

        @rmdir($path);
    }
}
