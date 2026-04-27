<?php

declare(strict_types=1);

namespace Foundry\Generate;

final readonly class GenerateTemplateResolution
{
    /**
     * @param array<string,mixed> $resolvedParameters
     * @param array<string,mixed> $resolvedDefinition
     */
    public function __construct(
        public GenerateTemplateDefinition $template,
        public array $resolvedParameters,
        public array $resolvedDefinition,
    ) {}

    /**
     * @return array<string,mixed>
     */
    public function metadata(): array
    {
        return [
            'template_id' => $this->template->templateId,
            'path' => $this->template->path,
            'description' => $this->template->description,
            'generate_type' => $this->template->generateType,
            'resolved_parameters' => $this->resolvedParameters,
        ];
    }
}
