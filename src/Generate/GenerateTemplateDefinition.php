<?php

declare(strict_types=1);

namespace Foundry\Generate;

final readonly class GenerateTemplateDefinition
{
    /**
     * @param array<string,array<string,mixed>> $parameters
     * @param array<string,mixed> $definition
     */
    public function __construct(
        public string $templateId,
        public string $path,
        public string $description,
        public array $parameters,
        public string $generateType,
        public array $definition,
    ) {}

    /**
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        return [
            'schema' => 'foundry.generate.template.v1',
            'template_id' => $this->templateId,
            'path' => $this->path,
            'description' => $this->description,
            'parameters' => $this->parameters,
            'generate' => [
                'type' => $this->generateType,
                'definition' => $this->definition,
            ],
        ];
    }
}
