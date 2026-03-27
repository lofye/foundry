<?php

declare(strict_types=1);

namespace Foundry\Feature;

final readonly class FeatureDefinition
{
    /**
     * @param array<string,mixed>|null $route
     * @param array<string,mixed> $auth
     * @param array<string,mixed> $database
     * @param array<string,mixed> $cache
     * @param array<string,mixed> $events
     * @param array<string,mixed> $jobs
     * @param array<string,mixed> $rateLimit
     * @param array<string,mixed> $tests
     * @param array<string,mixed> $llm
     */
    public function __construct(
        public readonly string $name,
        public readonly string $kind,
        public readonly string $description,
        public readonly ?array $route,
        public readonly string $inputSchemaPath,
        public readonly string $outputSchemaPath,
        public readonly array $auth,
        public readonly array $database,
        public readonly array $cache,
        public readonly array $events,
        public readonly array $jobs,
        public readonly array $rateLimit,
        public readonly array $tests,
        public readonly array $llm,
        public readonly string $basePath,
        public readonly ?string $actionClass = null,
    ) {}

    /**
     * @param array<string,mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            name: (string) ($data['feature'] ?? $data['name'] ?? ''),
            kind: (string) ($data['kind'] ?? ''),
            description: (string) ($data['description'] ?? ''),
            route: is_array($data['route'] ?? null) ? $data['route'] : null,
            inputSchemaPath: (string) ($data['input_schema'] ?? $data['input']['schema'] ?? ''),
            outputSchemaPath: (string) ($data['output_schema'] ?? $data['output']['schema'] ?? ''),
            auth: is_array($data['auth'] ?? null) ? $data['auth'] : [],
            database: is_array($data['database'] ?? null) ? $data['database'] : [],
            cache: is_array($data['cache'] ?? null) ? $data['cache'] : [],
            events: is_array($data['events'] ?? null) ? $data['events'] : [],
            jobs: is_array($data['jobs'] ?? null) ? $data['jobs'] : [],
            rateLimit: is_array($data['rate_limit'] ?? null) ? $data['rate_limit'] : [],
            tests: is_array($data['tests'] ?? null) ? $data['tests'] : [],
            llm: is_array($data['llm'] ?? null) ? $data['llm'] : [],
            basePath: (string) ($data['base_path'] ?? ''),
            actionClass: isset($data['action_class']) ? (string) $data['action_class'] : null,
        );
    }

    public function requiresTransaction(): bool
    {
        return (($this->database['transactions'] ?? '') === 'required');
    }
}
