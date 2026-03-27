<?php

declare(strict_types=1);

namespace Foundry\Feature;

final readonly class FeatureContextManifest
{
    /**
     * @param array<int,string> $relevantFiles
     * @param array<int,string> $generatedFiles
     * @param array<int,string> $upstreamDependencies
     * @param array<int,string> $downstreamDependents
     * @param array<string,string> $contracts
     * @param array<int,string> $tests
     * @param array<int,string> $forbiddenPaths
     */
    public function __construct(
        public readonly int $version,
        public readonly string $feature,
        public readonly string $kind,
        public readonly array $relevantFiles,
        public readonly array $generatedFiles,
        public readonly array $upstreamDependencies,
        public readonly array $downstreamDependents,
        public readonly array $contracts,
        public readonly array $tests,
        public readonly array $forbiddenPaths,
        public readonly string $riskLevel,
    ) {}

    /**
     * @param array<string,mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            version: (int) ($data['version'] ?? 1),
            feature: (string) ($data['feature'] ?? ''),
            kind: (string) ($data['kind'] ?? ''),
            relevantFiles: array_values(array_map('strval', (array) ($data['relevant_files'] ?? []))),
            generatedFiles: array_values(array_map('strval', (array) ($data['generated_files'] ?? []))),
            upstreamDependencies: array_values(array_map('strval', (array) ($data['upstream_dependencies'] ?? []))),
            downstreamDependents: array_values(array_map('strval', (array) ($data['downstream_dependents'] ?? []))),
            contracts: array_map('strval', (array) ($data['contracts'] ?? [])),
            tests: array_values(array_map('strval', (array) ($data['tests'] ?? []))),
            forbiddenPaths: array_values(array_map('strval', (array) ($data['forbidden_paths'] ?? []))),
            riskLevel: (string) ($data['risk_level'] ?? 'unknown'),
        );
    }

    /**
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        return [
            'version' => $this->version,
            'feature' => $this->feature,
            'kind' => $this->kind,
            'relevant_files' => $this->relevantFiles,
            'generated_files' => $this->generatedFiles,
            'upstream_dependencies' => $this->upstreamDependencies,
            'downstream_dependents' => $this->downstreamDependents,
            'contracts' => $this->contracts,
            'tests' => $this->tests,
            'forbidden_paths' => $this->forbiddenPaths,
            'risk_level' => $this->riskLevel,
        ];
    }
}
