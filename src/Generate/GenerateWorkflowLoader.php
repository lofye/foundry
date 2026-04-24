<?php

declare(strict_types=1);

namespace Foundry\Generate;

use Foundry\Support\FoundryError;
use Foundry\Support\Json;
use Foundry\Support\Paths;

final class GenerateWorkflowLoader
{
    public function __construct(private readonly Paths $paths) {}

    public function load(string $path): GenerateWorkflowDefinition
    {
        $relativePath = $this->normalizePath($path);
        $absolutePath = $this->paths->join($relativePath);
        if (!is_file($absolutePath)) {
            throw new FoundryError(
                'GENERATE_WORKFLOW_NOT_FOUND',
                'not_found',
                ['path' => $relativePath],
                'Generate workflow file not found.',
            );
        }

        $json = file_get_contents($absolutePath);
        if (!is_string($json) || trim($json) === '') {
            throw new FoundryError(
                'GENERATE_WORKFLOW_INVALID',
                'validation',
                ['path' => $relativePath],
                'Generate workflow file must contain valid JSON.',
            );
        }

        try {
            $payload = Json::decodeAssoc($json);
        } catch (\Throwable) {
            throw new FoundryError(
                'GENERATE_WORKFLOW_INVALID',
                'validation',
                ['path' => $relativePath],
                'Generate workflow file must contain valid JSON.',
            );
        }

        $sharedContext = is_array($payload['shared_context'] ?? null) ? $payload['shared_context'] : [];
        $rawSteps = array_values(array_filter((array) ($payload['steps'] ?? []), 'is_array'));
        if ($rawSteps === []) {
            throw new FoundryError(
                'GENERATE_WORKFLOW_STEPS_REQUIRED',
                'validation',
                ['path' => $relativePath],
                'Generate workflow must define at least one step.',
            );
        }

        $steps = [];
        $seenIds = [];
        foreach ($rawSteps as $index => $rawStep) {
            $stepId = trim((string) ($rawStep['id'] ?? ''));
            if ($stepId === '') {
                throw new FoundryError(
                    'GENERATE_WORKFLOW_STEP_ID_REQUIRED',
                    'validation',
                    ['path' => $relativePath, 'step_index' => $index],
                    'Each generate workflow step requires an id.',
                );
            }

            if (isset($seenIds[$stepId])) {
                throw new FoundryError(
                    'GENERATE_WORKFLOW_STEP_ID_DUPLICATE',
                    'validation',
                    ['path' => $relativePath, 'step_id' => $stepId],
                    'Generate workflow step ids must be unique.',
                );
            }

            $rawIntent = trim((string) ($rawStep['intent'] ?? ''));
            if ($rawIntent === '') {
                throw new FoundryError(
                    'GENERATE_WORKFLOW_STEP_INTENT_REQUIRED',
                    'validation',
                    ['path' => $relativePath, 'step_id' => $stepId],
                    'Each generate workflow step requires an intent.',
                );
            }

            $mode = trim((string) ($rawStep['mode'] ?? ''));
            if (!in_array($mode, Intent::supportedModes(), true)) {
                throw new FoundryError(
                    'GENERATE_WORKFLOW_STEP_MODE_INVALID',
                    'validation',
                    ['path' => $relativePath, 'step_id' => $stepId, 'mode' => $mode],
                    'Generate workflow step mode must be new, modify, or repair.',
                );
            }

            $target = is_string($rawStep['target'] ?? null) ? trim((string) $rawStep['target']) : null;
            if (in_array($mode, ['modify', 'repair'], true) && ($target === null || $target === '')) {
                throw new FoundryError(
                    'GENERATE_WORKFLOW_STEP_TARGET_REQUIRED',
                    'validation',
                    ['path' => $relativePath, 'step_id' => $stepId, 'mode' => $mode],
                    'Modify and repair workflow steps require a target.',
                );
            }

            $packHints = array_values(array_filter(array_map(
                static fn(mixed $pack): string => trim((string) $pack),
                (array) ($rawStep['packs'] ?? []),
            ), static fn(string $pack): bool => $pack !== ''));
            $packHints = array_values(array_unique($packHints));
            sort($packHints);

            $dependencies = array_values(array_filter(array_map(
                static fn(mixed $dependency): string => trim((string) $dependency),
                (array) ($rawStep['dependencies'] ?? []),
            ), static fn(string $dependency): bool => $dependency !== ''));
            $dependencies = array_values(array_unique($dependencies));

            foreach ($dependencies as $dependency) {
                if (!isset($seenIds[$dependency])) {
                    throw new FoundryError(
                        'GENERATE_WORKFLOW_DEPENDENCY_INVALID',
                        'validation',
                        ['path' => $relativePath, 'step_id' => $stepId, 'dependency' => $dependency],
                        'Workflow step dependencies must reference an earlier declared step.',
                    );
                }
            }

            $seenIds[$stepId] = true;
            $steps[] = new GenerateWorkflowStepDefinition(
                id: $stepId,
                description: trim((string) ($rawStep['description'] ?? '')) ?: $rawIntent,
                rawIntent: $rawIntent,
                mode: $mode,
                target: $target !== '' ? $target : null,
                packHints: $packHints,
                dependencies: $dependencies,
            );
        }

        return new GenerateWorkflowDefinition(
            id: hash('sha256', Json::encode([
                'path' => $relativePath,
                'shared_context' => $sharedContext,
                'steps' => array_values(array_map(
                    static fn(GenerateWorkflowStepDefinition $step): array => $step->toArray(),
                    $steps,
                )),
            ])),
            path: $relativePath,
            sharedContext: $sharedContext,
            steps: $steps,
        );
    }

    private function normalizePath(string $path): string
    {
        $path = trim($path);
        if ($path === '') {
            throw new FoundryError(
                'GENERATE_WORKFLOW_PATH_REQUIRED',
                'validation',
                [],
                'Generate workflow path is required.',
            );
        }

        if (str_starts_with($path, '/')) {
            $root = rtrim(str_replace('\\', '/', $this->paths->root()), '/') . '/';
            $normalized = str_replace('\\', '/', $path);

            return str_starts_with($normalized, $root)
                ? substr($normalized, strlen($root))
                : ltrim($normalized, '/');
        }

        return ltrim(str_replace('\\', '/', $path), '/');
    }
}
