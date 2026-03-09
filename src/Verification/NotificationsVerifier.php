<?php
declare(strict_types=1);

namespace Foundry\Verification;

use Foundry\Compiler\CompileOptions;
use Foundry\Compiler\GraphCompiler;
use Foundry\Support\Paths;

final class NotificationsVerifier
{
    public function __construct(
        private readonly GraphCompiler $compiler,
        private readonly Paths $paths,
    )
    {
    }

    public function verify(?string $name = null): VerificationResult
    {
        $result = $this->compiler->compile(new CompileOptions());
        $graph = $result->graph;

        $errors = [];
        $warnings = [];

        $nodes = $name === null || $name === ''
            ? $graph->nodesByType('notification')
            : ['notification:' . $name => $graph->node('notification:' . $name)];

        if ($name !== null && $name !== '' && !isset($nodes['notification:' . $name])) {
            $errors[] = 'Notification not found in compiled graph: ' . $name;
        }

        foreach ($nodes as $node) {
            if (!$node instanceof \Foundry\Compiler\IR\GraphNode) {
                continue;
            }

            $payload = $node->payload();
            $notification = (string) ($payload['notification'] ?? '');
            if ($notification === '') {
                continue;
            }

            $channel = (string) ($payload['channel'] ?? '');
            if ($channel !== 'mail') {
                $errors[] = sprintf('Notification %s uses unsupported channel %s.', $notification, $channel);
            }

            $templatePath = (string) ($payload['template_path'] ?? '');
            if ($templatePath === '') {
                $errors[] = sprintf('Notification %s is missing template path.', $notification);
            } elseif (!is_file($this->paths->join($templatePath))) {
                $errors[] = sprintf('Notification %s template does not exist: %s', $notification, $templatePath);
            }

            $queue = (string) ($payload['queue'] ?? '');
            if ($queue === '') {
                $errors[] = sprintf('Notification %s must define queue.', $notification);
            }

            $schemaPath = (string) ($payload['input_schema_path'] ?? '');
            $schemaInline = is_array($payload['input_schema'] ?? null) ? $payload['input_schema'] : null;
            $hasSchema = $schemaInline !== null;
            if (!$hasSchema && $schemaPath !== '') {
                $hasSchema = is_file($this->paths->join($schemaPath));
            }

            if (!$hasSchema) {
                $errors[] = sprintf('Notification %s input schema missing.', $notification);
            }

            $dispatchFeatures = array_values(array_map('strval', (array) ($payload['dispatch_features'] ?? [])));
            if ($dispatchFeatures === []) {
                $warnings[] = sprintf('Notification %s is not linked to any dispatch feature.', $notification);
            }
        }

        sort($errors);
        sort($warnings);

        return new VerificationResult($errors === [], $errors, $warnings);
    }
}
