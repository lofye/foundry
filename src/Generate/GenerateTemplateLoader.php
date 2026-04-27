<?php

declare(strict_types=1);

namespace Foundry\Generate;

use Foundry\Support\FoundryError;
use Foundry\Support\Json;
use Foundry\Support\Paths;

final class GenerateTemplateLoader
{
    public function __construct(private readonly Paths $paths) {}

    public function load(string $templateId): GenerateTemplateDefinition
    {
        $templateId = trim($templateId);
        if ($templateId === '') {
            throw new FoundryError(
                'GENERATE_TEMPLATE_ID_REQUIRED',
                'validation',
                [],
                'Generate template id is required.',
            );
        }

        $matches = [];
        foreach ($this->templatePaths() as $path) {
            $definition = $this->loadPath($path);
            if ($definition->templateId !== $templateId) {
                continue;
            }

            $matches[] = $definition;
        }

        if ($matches === []) {
            throw new FoundryError(
                'GENERATE_TEMPLATE_NOT_FOUND',
                'not_found',
                ['template_id' => $templateId],
                'Generate template not found.',
            );
        }

        if (count($matches) > 1) {
            throw new FoundryError(
                'GENERATE_TEMPLATE_ID_DUPLICATE',
                'validation',
                [
                    'template_id' => $templateId,
                    'paths' => array_values(array_map(
                        static fn(GenerateTemplateDefinition $definition): string => $definition->path,
                        $matches,
                    )),
                ],
                'Generate template ids must be unique within the repository.',
            );
        }

        return $matches[0];
    }

    /**
     * @return list<string>
     */
    private function templatePaths(): array
    {
        $paths = glob($this->paths->join('.foundry/templates/*.json')) ?: [];
        $paths = array_values(array_map('strval', $paths));
        sort($paths);

        return $paths;
    }

    private function loadPath(string $path): GenerateTemplateDefinition
    {
        $relativePath = $this->relativePath($path);
        $json = file_get_contents($path);
        if (!is_string($json) || trim($json) === '') {
            throw new FoundryError(
                'GENERATE_TEMPLATE_INVALID',
                'validation',
                ['path' => $relativePath],
                'Generate template file must contain valid JSON.',
            );
        }

        try {
            $payload = Json::decodeAssoc($json);
        } catch (\Throwable) {
            throw new FoundryError(
                'GENERATE_TEMPLATE_INVALID',
                'validation',
                ['path' => $relativePath],
                'Generate template file must contain valid JSON.',
            );
        }

        if ((string) ($payload['schema'] ?? '') !== 'foundry.generate.template.v1') {
            throw new FoundryError(
                'GENERATE_TEMPLATE_SCHEMA_INVALID',
                'validation',
                ['path' => $relativePath],
                'Generate template schema must be foundry.generate.template.v1.',
            );
        }

        $templateId = trim((string) ($payload['template_id'] ?? ''));
        if ($templateId === '') {
            throw new FoundryError(
                'GENERATE_TEMPLATE_ID_REQUIRED',
                'validation',
                ['path' => $relativePath],
                'Generate template file must declare template_id.',
            );
        }

        $description = trim((string) ($payload['description'] ?? ''));
        if ($description === '') {
            throw new FoundryError(
                'GENERATE_TEMPLATE_DESCRIPTION_REQUIRED',
                'validation',
                ['path' => $relativePath, 'template_id' => $templateId],
                'Generate template file must declare a description.',
            );
        }

        $parameters = is_array($payload['parameters'] ?? null) ? $payload['parameters'] : [];
        $validatedParameters = [];
        foreach ($parameters as $name => $parameter) {
            $parameterName = trim((string) $name);
            if ($parameterName === '' || !is_array($parameter)) {
                throw new FoundryError(
                    'GENERATE_TEMPLATE_PARAMETER_INVALID',
                    'validation',
                    ['path' => $relativePath, 'template_id' => $templateId, 'parameter' => $name],
                    'Generate template parameters must be keyed objects.',
                );
            }

            $type = trim((string) ($parameter['type'] ?? ''));
            if (!in_array($type, ['string', 'number', 'boolean', 'array', 'object'], true)) {
                throw new FoundryError(
                    'GENERATE_TEMPLATE_PARAMETER_TYPE_INVALID',
                    'validation',
                    ['path' => $relativePath, 'template_id' => $templateId, 'parameter' => $parameterName, 'type' => $type],
                    'Generate template parameter type must be string, number, boolean, array, or object.',
                );
            }

            $required = ($parameter['required'] ?? false) === true;
            $normalized = [
                'type' => $type,
                'required' => $required,
            ];

            if (array_key_exists('default', $parameter)) {
                $this->assertParameterType($parameter['default'], $type, $relativePath, $templateId, $parameterName, 'default');
                $normalized['default'] = $parameter['default'];
            }

            $validatedParameters[$parameterName] = $normalized;
        }
        ksort($validatedParameters);

        $generate = is_array($payload['generate'] ?? null) ? $payload['generate'] : null;
        if ($generate === null) {
            throw new FoundryError(
                'GENERATE_TEMPLATE_GENERATE_REQUIRED',
                'validation',
                ['path' => $relativePath, 'template_id' => $templateId],
                'Generate template file must declare generate.type and generate.definition.',
            );
        }

        $generateType = trim((string) ($generate['type'] ?? ''));
        if (!in_array($generateType, ['single', 'workflow'], true)) {
            throw new FoundryError(
                'GENERATE_TEMPLATE_GENERATE_TYPE_INVALID',
                'validation',
                ['path' => $relativePath, 'template_id' => $templateId, 'type' => $generateType],
                'Generate template type must be single or workflow.',
            );
        }

        $definition = $generate['definition'] ?? null;
        if (!is_array($definition)) {
            throw new FoundryError(
                'GENERATE_TEMPLATE_DEFINITION_INVALID',
                'validation',
                ['path' => $relativePath, 'template_id' => $templateId],
                'Generate template definition must be a JSON object.',
            );
        }

        return new GenerateTemplateDefinition(
            templateId: $templateId,
            path: $relativePath,
            description: $description,
            parameters: $validatedParameters,
            generateType: $generateType,
            definition: $definition,
        );
    }

    private function relativePath(string $path): string
    {
        $root = rtrim(str_replace('\\', '/', $this->paths->root()), '/') . '/';
        $normalized = str_replace('\\', '/', $path);

        return str_starts_with($normalized, $root)
            ? substr($normalized, strlen($root))
            : ltrim($normalized, '/');
    }

    private function assertParameterType(
        mixed $value,
        string $type,
        string $path,
        string $templateId,
        string $parameterName,
        string $source,
    ): void {
        $valid = match ($type) {
            'string' => is_string($value),
            'number' => is_int($value) || is_float($value),
            'boolean' => is_bool($value),
            'array' => is_array($value) && array_is_list($value),
            'object' => is_array($value) && !array_is_list($value),
            default => false,
        };

        if ($valid) {
            return;
        }

        throw new FoundryError(
            'GENERATE_TEMPLATE_PARAMETER_TYPE_INVALID',
            'validation',
            [
                'path' => $path,
                'template_id' => $templateId,
                'parameter' => $parameterName,
                'source' => $source,
                'type' => $type,
            ],
            'Generate template parameter value does not match its declared type.',
        );
    }
}
