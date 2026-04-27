<?php

declare(strict_types=1);

namespace Foundry\Generate;

use Foundry\Support\FoundryError;
use Foundry\Support\Json;

final class GenerateTemplateResolver
{
    /**
     * @param array<string,string> $inputParameters
     */
    public function resolve(GenerateTemplateDefinition $template, array $inputParameters): GenerateTemplateResolution
    {
        $resolvedParameters = $this->resolveParameters($template, $inputParameters);
        $resolvedDefinition = $this->resolveValue($template->definition, $resolvedParameters, $template);
        if (!is_array($resolvedDefinition)) {
            throw new FoundryError(
                'GENERATE_TEMPLATE_DEFINITION_INVALID',
                'validation',
                ['template_id' => $template->templateId, 'path' => $template->path],
                'Resolved generate template definition must stay a JSON object.',
            );
        }

        $first = $this->normalizeForHash($resolvedDefinition);
        $second = $this->normalizeForHash($this->resolveValue($template->definition, $resolvedParameters, $template));
        if (Json::encode($first) !== Json::encode($second)) {
            throw new FoundryError(
                'GENERATE_TEMPLATE_NON_DETERMINISTIC',
                'validation',
                ['template_id' => $template->templateId, 'path' => $template->path],
                'Generate template resolution must be deterministic.',
            );
        }

        return new GenerateTemplateResolution(
            template: $template,
            resolvedParameters: $resolvedParameters,
            resolvedDefinition: $resolvedDefinition,
        );
    }

    /**
     * @param array<string,string> $inputParameters
     * @return array<string,mixed>
     */
    private function resolveParameters(GenerateTemplateDefinition $template, array $inputParameters): array
    {
        $resolved = [];
        foreach ($inputParameters as $name => $_value) {
            if (!array_key_exists($name, $template->parameters)) {
                throw new FoundryError(
                    'GENERATE_TEMPLATE_PARAMETER_UNKNOWN',
                    'validation',
                    ['template_id' => $template->templateId, 'path' => $template->path, 'parameter' => $name],
                    'Generate template does not declare the provided parameter.',
                );
            }
        }

        foreach ($template->parameters as $name => $definition) {
            if (array_key_exists($name, $inputParameters)) {
                $resolved[$name] = $this->coerceInputValue(
                    $inputParameters[$name],
                    (string) ($definition['type'] ?? 'string'),
                    $template,
                    $name,
                );
                continue;
            }

            if (array_key_exists('default', $definition)) {
                $resolved[$name] = $definition['default'];
                continue;
            }

            if (($definition['required'] ?? false) === true) {
                throw new FoundryError(
                    'GENERATE_TEMPLATE_PARAMETER_REQUIRED',
                    'validation',
                    ['template_id' => $template->templateId, 'path' => $template->path, 'parameter' => $name],
                    'Generate template requires all declared required parameters.',
                );
            }

            $resolved[$name] = match ((string) ($definition['type'] ?? 'string')) {
                'array' => [],
                'object' => [],
                default => null,
            };
        }

        ksort($resolved);

        return $resolved;
    }

    private function coerceInputValue(
        string $value,
        string $type,
        GenerateTemplateDefinition $template,
        string $parameterName,
    ): mixed {
        return match ($type) {
            'string' => $value,
            'number' => $this->parseNumber($value, $template, $parameterName),
            'boolean' => $this->parseBoolean($value, $template, $parameterName),
            'array' => $this->parseStructuredValue($value, $template, $parameterName, true),
            'object' => $this->parseStructuredValue($value, $template, $parameterName, false),
            default => throw new FoundryError(
                'GENERATE_TEMPLATE_PARAMETER_TYPE_INVALID',
                'validation',
                ['template_id' => $template->templateId, 'path' => $template->path, 'parameter' => $parameterName, 'type' => $type],
                'Generate template parameter type is invalid.',
            ),
        };
    }

    private function parseNumber(string $value, GenerateTemplateDefinition $template, string $parameterName): int|float
    {
        $trimmed = trim($value);
        if (!is_numeric($trimmed)) {
            throw new FoundryError(
                'GENERATE_TEMPLATE_PARAMETER_VALUE_INVALID',
                'validation',
                ['template_id' => $template->templateId, 'path' => $template->path, 'parameter' => $parameterName, 'expected_type' => 'number'],
                'Generate template parameter value does not match its declared type.',
            );
        }

        if (preg_match('/^-?[0-9]+$/', $trimmed) === 1) {
            return (int) $trimmed;
        }

        return (float) $trimmed;
    }

    private function parseBoolean(string $value, GenerateTemplateDefinition $template, string $parameterName): bool
    {
        $normalized = strtolower(trim($value));
        if ($normalized === 'true') {
            return true;
        }

        if ($normalized === 'false') {
            return false;
        }

        throw new FoundryError(
            'GENERATE_TEMPLATE_PARAMETER_VALUE_INVALID',
            'validation',
            ['template_id' => $template->templateId, 'path' => $template->path, 'parameter' => $parameterName, 'expected_type' => 'boolean'],
            'Generate template parameter value does not match its declared type.',
        );
    }

    private function parseStructuredValue(
        string $value,
        GenerateTemplateDefinition $template,
        string $parameterName,
        bool $expectList,
    ): array {
        try {
            /** @var mixed $decoded */
            $decoded = json_decode($value, true, 512, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            throw new FoundryError(
                'GENERATE_TEMPLATE_PARAMETER_VALUE_INVALID',
                'validation',
                [
                    'template_id' => $template->templateId,
                    'path' => $template->path,
                    'parameter' => $parameterName,
                    'expected_type' => $expectList ? 'array' : 'object',
                ],
                'Generate template parameter value does not match its declared type.',
            );
        }

        if (!is_array($decoded) || array_is_list($decoded) !== $expectList) {
            throw new FoundryError(
                'GENERATE_TEMPLATE_PARAMETER_VALUE_INVALID',
                'validation',
                [
                    'template_id' => $template->templateId,
                    'path' => $template->path,
                    'parameter' => $parameterName,
                    'expected_type' => $expectList ? 'array' : 'object',
                ],
                'Generate template parameter value does not match its declared type.',
            );
        }

        return $decoded;
    }

    private function resolveValue(mixed $value, array $parameters, GenerateTemplateDefinition $template): mixed
    {
        if (is_array($value)) {
            foreach ($value as $key => $item) {
                $value[$key] = $this->resolveValue($item, $parameters, $template);
            }

            return $value;
        }

        if (!is_string($value)) {
            return $value;
        }

        if (preg_match('/^\{\{\s*(parameters\.[a-zA-Z0-9_.-]+)\s*\}\}$/', $value, $matches) === 1) {
            return $this->lookup($parameters, (string) $matches[1], $template);
        }

        $resolved = (string) preg_replace_callback(
            '/\{\{\s*(parameters\.[a-zA-Z0-9_.-]+)\s*\}\}/',
            function (array $matches) use ($parameters, $template): string {
                $resolved = $this->lookup($parameters, (string) ($matches[1] ?? ''), $template);
                if (is_array($resolved)) {
                    throw new FoundryError(
                        'GENERATE_TEMPLATE_PARAMETER_INTERPOLATION_INVALID',
                        'validation',
                        ['template_id' => $template->templateId, 'path' => $template->path, 'path_ref' => (string) ($matches[1] ?? '')],
                        'Generate template placeholders embedded in strings must resolve to scalar values.',
                    );
                }

                if (is_bool($resolved)) {
                    return $resolved ? 'true' : 'false';
                }

                return (string) $resolved;
            },
            $value,
        );

        if (
            preg_match('/\{\{\s*([^}]+)\s*\}\}/', $resolved, $unresolved) === 1
            && !$this->allowUnresolvedPlaceholder((string) ($unresolved[1] ?? ''), $template)
        ) {
            throw new FoundryError(
                'GENERATE_TEMPLATE_PARAMETER_REFERENCE_INVALID',
                'validation',
                ['template_id' => $template->templateId, 'path' => $template->path, 'value' => $value],
                'Generate template placeholders must reference declared parameters.',
            );
        }

        return $resolved;
    }

    private function allowUnresolvedPlaceholder(string $path, GenerateTemplateDefinition $template): bool
    {
        $path = trim($path);

        if ($template->generateType !== 'workflow') {
            return false;
        }

        return str_starts_with($path, 'shared.') || str_starts_with($path, 'steps.');
    }

    private function lookup(array $parameters, string $path, GenerateTemplateDefinition $template): mixed
    {
        $segments = explode('.', $path);
        if (array_shift($segments) !== 'parameters') {
            throw new FoundryError(
                'GENERATE_TEMPLATE_PARAMETER_REFERENCE_INVALID',
                'validation',
                ['template_id' => $template->templateId, 'path' => $template->path, 'path_ref' => $path],
                'Generate template placeholders must reference declared parameters.',
            );
        }

        $current = $parameters;
        foreach ($segments as $segment) {
            if (!is_array($current) || !array_key_exists($segment, $current)) {
                throw new FoundryError(
                    'GENERATE_TEMPLATE_PARAMETER_REFERENCE_INVALID',
                    'validation',
                    ['template_id' => $template->templateId, 'path' => $template->path, 'path_ref' => $path],
                    'Generate template placeholder could not be resolved from declared parameters.',
                );
            }

            $current = $current[$segment];
        }

        return $current;
    }

    private function normalizeForHash(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }

        if (array_is_list($value)) {
            return array_map($this->normalizeForHash(...), $value);
        }

        ksort($value);
        foreach ($value as $key => $item) {
            $value[$key] = $this->normalizeForHash($item);
        }

        return $value;
    }
}
