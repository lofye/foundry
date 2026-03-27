<?php

declare(strict_types=1);

namespace Foundry\Verification;

use Foundry\Compiler\CompileOptions;
use Foundry\Compiler\GraphCompiler;

final class StreamsVerifier
{
    public function __construct(private readonly GraphCompiler $compiler) {}

    public function verify(?string $name = null): VerificationResult
    {
        $graph = $this->compiler->compile(new CompileOptions())->graph;

        $errors = [];
        $warnings = [];

        $nodes = $name === null || $name === ''
            ? $graph->nodesByType('stream')
            : ['stream:' . $name => $graph->node('stream:' . $name)];

        if ($name !== null && $name !== '' && !isset($nodes['stream:' . $name])) {
            $errors[] = 'Stream not found in compiled graph: ' . $name;
        }

        $routes = [];
        foreach ($nodes as $node) {
            if (!$node instanceof \Foundry\Compiler\IR\GraphNode) {
                continue;
            }
            $payload = $node->payload();
            $stream = (string) ($payload['stream'] ?? '');
            $transport = (string) ($payload['transport'] ?? 'sse');
            if ($transport !== 'sse') {
                $errors[] = sprintf('Stream %s transport must be sse.', $stream);
            }

            $routePath = (string) ($payload['route']['path'] ?? '');
            if ($routePath === '') {
                $errors[] = sprintf('Stream %s route path missing.', $stream);
            } elseif (isset($routes[$routePath])) {
                $errors[] = sprintf('Stream route conflict at %s.', $routePath);
            }
            $routes[$routePath] = $stream;

            $auth = is_array($payload['auth'] ?? null) ? $payload['auth'] : [];
            if (!isset($auth['required'])) {
                $warnings[] = sprintf('Stream %s should declare auth.required explicitly.', $stream);
            }
        }

        sort($errors);
        sort($warnings);

        return new VerificationResult($errors === [], $errors, $warnings);
    }
}
