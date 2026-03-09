<?php
declare(strict_types=1);

namespace Foundry\Notifications;

use Foundry\Compiler\CompileOptions;
use Foundry\Compiler\GraphCompiler;
use Foundry\Support\FoundryError;
use Foundry\Support\Json;
use Foundry\Support\Paths;

final class NotificationPreviewer
{
    public function __construct(
        private readonly Paths $paths,
        private readonly GraphCompiler $compiler,
        private readonly NotificationTemplateRenderer $renderer = new NotificationTemplateRenderer(),
    ) {
    }

    /**
     * @return array<string,mixed>
     */
    public function preview(string $name): array
    {
        $name = trim($name);
        if ($name === '') {
            throw new FoundryError('NOTIFICATION_REQUIRED', 'validation', [], 'Notification name required.');
        }

        $graph = $this->compiler->loadGraph() ?? $this->compiler->compile(new CompileOptions())->graph;
        $node = $graph->node('notification:' . $name);
        if ($node === null) {
            throw new FoundryError('NOTIFICATION_NOT_FOUND', 'not_found', ['notification' => $name], 'Notification not found in graph.');
        }

        $payload = $node->payload();
        $templatePath = (string) ($payload['template_path'] ?? '');
        if ($templatePath === '') {
            throw new FoundryError('NOTIFICATION_TEMPLATE_NOT_CONFIGURED', 'notifications', ['notification' => $name], 'Notification template path is not configured.');
        }

        $schema = is_array($payload['input_schema'] ?? null)
            ? (array) $payload['input_schema']
            : $this->loadSchema((string) ($payload['input_schema_path'] ?? ''));

        $sampleInput = $this->sampleInput($schema);
        $rendered = $this->renderer->render($this->paths->join($templatePath), $sampleInput);

        return [
            'notification' => $name,
            'channel' => (string) ($payload['channel'] ?? 'mail'),
            'queue' => (string) ($payload['queue'] ?? 'default'),
            'template_path' => $templatePath,
            'sample_input' => $sampleInput,
            'rendered' => $rendered,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function loadSchema(string $relativePath): array
    {
        if ($relativePath === '') {
            return [];
        }

        $path = $this->paths->join($relativePath);
        if (!is_file($path)) {
            return [];
        }

        $raw = file_get_contents($path);
        if (!is_string($raw) || $raw === '') {
            return [];
        }

        try {
            return Json::decodeAssoc($raw);
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * @param array<string,mixed> $schema
     * @return array<string,mixed>
     */
    private function sampleInput(array $schema): array
    {
        $properties = is_array($schema['properties'] ?? null) ? $schema['properties'] : [];
        $required = array_values(array_map('strval', (array) ($schema['required'] ?? [])));

        $input = [];
        foreach ($required as $name) {
            if ($name === '') {
                continue;
            }
            $definition = is_array($properties[$name] ?? null) ? $properties[$name] : [];
            $input[$name] = $this->sampleValue($name, $definition);
        }

        if ($input === []) {
            foreach ($properties as $name => $definition) {
                if (!is_string($name) || $name === '' || !is_array($definition)) {
                    continue;
                }
                $input[$name] = $this->sampleValue($name, $definition);
                if (count($input) >= 3) {
                    break;
                }
            }
        }

        ksort($input);

        return $input;
    }

    /**
     * @param array<string,mixed> $definition
     */
    private function sampleValue(string $field, array $definition): mixed
    {
        $enum = (array) ($definition['enum'] ?? []);
        if ($enum !== []) {
            return $enum[0];
        }

        $type = (string) ($definition['type'] ?? 'string');
        return match ($type) {
            'integer' => 1,
            'number' => 1.0,
            'boolean' => true,
            'array' => [],
            'object' => [],
            default => 'sample_' . $field,
        };
    }
}
