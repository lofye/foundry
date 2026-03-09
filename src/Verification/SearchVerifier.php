<?php
declare(strict_types=1);

namespace Foundry\Verification;

use Foundry\Compiler\CompileOptions;
use Foundry\Compiler\GraphCompiler;

final class SearchVerifier
{
    public function __construct(private readonly GraphCompiler $compiler)
    {
    }

    public function verify(?string $name = null): VerificationResult
    {
        $graph = $this->compiler->compile(new CompileOptions())->graph;

        $errors = [];
        $warnings = [];

        $nodes = $name === null || $name === ''
            ? $graph->nodesByType('search_index')
            : ['search_index:' . $name => $graph->node('search_index:' . $name)];

        if ($name !== null && $name !== '' && !isset($nodes['search_index:' . $name])) {
            $errors[] = 'Search index not found in compiled graph: ' . $name;
        }

        foreach ($nodes as $node) {
            if (!$node instanceof \Foundry\Compiler\IR\GraphNode) {
                continue;
            }
            $payload = $node->payload();
            $index = (string) ($payload['index'] ?? '');
            $adapter = strtolower((string) ($payload['adapter'] ?? 'sql'));
            if (!in_array($adapter, ['sql', 'meilisearch', 'postgres'], true)) {
                $errors[] = sprintf('Search index %s uses unsupported adapter %s.', $index, $adapter);
            }

            $fields = array_values(array_map('strval', (array) ($payload['fields'] ?? [])));
            if ($fields === []) {
                $warnings[] = sprintf('Search index %s defines no fields.', $index);
            }
        }

        sort($errors);
        sort($warnings);

        return new VerificationResult($errors === [], $errors, $warnings);
    }
}
