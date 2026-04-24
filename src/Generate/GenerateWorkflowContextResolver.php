<?php

declare(strict_types=1);

namespace Foundry\Generate;

use Foundry\Support\FoundryError;

final class GenerateWorkflowContextResolver
{
    /**
     * @param array<string,mixed> $context
     */
    public function resolveStep(GenerateWorkflowStepDefinition $step, array $context): GenerateWorkflowStepDefinition
    {
        $packHints = array_values(array_map(
            fn(string $pack): string => $this->resolveString($pack, $context, $step->id),
            $step->packHints,
        ));
        $packHints = array_values(array_unique($packHints));
        sort($packHints);

        return new GenerateWorkflowStepDefinition(
            id: $step->id,
            description: $this->resolveString($step->description, $context, $step->id),
            rawIntent: $this->resolveString($step->rawIntent, $context, $step->id),
            mode: $step->mode,
            target: $step->target !== null ? $this->resolveString($step->target, $context, $step->id) : null,
            packHints: $packHints,
            dependencies: $step->dependencies,
        );
    }

    /**
     * @param array<string,mixed> $context
     */
    public function resolveString(string $value, array $context, string $stepId): string
    {
        return (string) preg_replace_callback(
            '/\{\{\s*([a-zA-Z0-9_.-]+)\s*\}\}/',
            function (array $matches) use ($context, $stepId): string {
                $path = trim((string) ($matches[1] ?? ''));
                $resolved = $this->lookup($context, $path);
                if (is_array($resolved)) {
                    throw new FoundryError(
                        'GENERATE_WORKFLOW_CONTEXT_INVALID',
                        'validation',
                        ['step_id' => $stepId, 'path' => $path],
                        'Generate workflow placeholders must resolve to scalar values.',
                    );
                }

                if (is_bool($resolved)) {
                    return $resolved ? 'true' : 'false';
                }

                return trim((string) $resolved);
            },
            $value,
        );
    }

    /**
     * @param array<string,mixed> $context
     */
    private function lookup(array $context, string $path): mixed
    {
        $current = $context;
        foreach (explode('.', $path) as $segment) {
            if (!is_array($current) || !array_key_exists($segment, $current)) {
                throw new FoundryError(
                    'GENERATE_WORKFLOW_CONTEXT_MISSING',
                    'validation',
                    ['path' => $path],
                    'Generate workflow placeholder could not be resolved from shared context.',
                );
            }

            $current = $current[$segment];
        }

        return $current;
    }
}
