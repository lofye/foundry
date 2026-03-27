<?php

declare(strict_types=1);

namespace Foundry\Compiler;

use Foundry\Compiler\Diagnostics\DiagnosticBag;

final readonly class CompileResult
{
    /**
     * @param array<string,mixed> $manifest
     * @param array<string,array<string,mixed>> $configSchemas
     * @param array<string,mixed> $configValidation
     * @param array<string,string> $integrityHashes
     * @param array<string,mixed> $projections
     * @param array<string,mixed> $cache
     * @param array<int,string> $writtenFiles
     */
    public function __construct(
        public ApplicationGraph $graph,
        public DiagnosticBag $diagnostics,
        public CompilePlan $plan,
        public array $manifest,
        public array $configSchemas,
        public array $configValidation,
        public array $integrityHashes,
        public array $projections,
        public array $cache,
        public array $writtenFiles,
    ) {}

    /**
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        return [
            'plan' => $this->plan->toArray(),
            'manifest' => $this->manifest,
            'diagnostics' => [
                'summary' => $this->diagnostics->summary(),
                'items' => $this->diagnostics->toArray(),
            ],
            'cache' => $this->cache,
            'graph' => [
                'graph_version' => $this->graph->graphVersion(),
                'framework_version' => $this->graph->frameworkVersion(),
                'compiled_at' => $this->graph->compiledAt(),
                'source_hash' => $this->graph->sourceHash(),
                'summary' => [
                    'node_counts' => $this->graph->nodeCountsByType(),
                    'edge_counts' => $this->graph->edgeCountsByType(),
                ],
            ],
            'config' => [
                'schemas' => [
                    'count' => count($this->configSchemas),
                    'path' => (string) (($this->manifest['config_schemas']['path'] ?? '')),
                ],
                'validation' => $this->configValidation,
            ],
            'integrity_hashes' => $this->integrityHashes,
            'written_files' => $this->writtenFiles,
        ];
    }
}
