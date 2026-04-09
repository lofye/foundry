<?php

declare(strict_types=1);

namespace Foundry\Generation;

use Foundry\Support\FoundryError;
use Foundry\Support\Json;
use Foundry\Support\Paths;
use Foundry\Support\Str;
use Foundry\Support\Yaml;

final class FeatureGenerator
{
    public function __construct(
        private readonly Paths $paths,
        private readonly SchemaGenerator $schemas = new SchemaGenerator(),
        private readonly QueryGenerator $queries = new QueryGenerator(),
        private readonly TestGenerator $tests = new TestGenerator(),
    ) {}

    /**
     * @return array<int,string>
     */
    public function generateFromDefinition(string $definitionPath, bool $force = false): array
    {
        $definition = Yaml::parseFile($definitionPath);

        return $this->generateFromArray($definition, $force);
    }

    /**
     * @param array<string,mixed> $definition
     * @return array<int,string>
     */
    public function generateFromArray(array $definition, bool $force = false): array
    {
        $feature = (string) ($definition['feature'] ?? '');
        $canonicalFeature = (string) ($definition['canonical_feature'] ?? $feature);

        if ($feature === '' || !Str::isSnakeCase($feature)) {
            throw new FoundryError('FEATURE_NAME_INVALID', 'validation', ['feature' => $feature], 'Feature name must be snake_case.');
        }

        if (array_key_exists('canonical_feature', $definition) && preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $canonicalFeature) !== 1) {
            throw new FoundryError(
                'FEATURE_CANONICAL_NAME_INVALID',
                'validation',
                ['canonical_feature' => $canonicalFeature],
                'Canonical feature name must be lowercase kebab-case.',
            );
        }

        $base = $this->paths->join('app/features/' . $canonicalFeature);
        if (!is_dir($base)) {
            mkdir($base, 0777, true);
        }

        $manifest = $this->buildFeatureManifest($definition, $canonicalFeature);

        $written = [];
        $written[] = $this->writeIfAllowed($base . '/feature.yaml', Yaml::dump($manifest), true, $force);
        $written[] = $this->writeIfAllowed($base . '/input.schema.json', Json::encode($this->schemas->fromFieldDefinition($feature . '_input', (array) $definition['input']), true) . "\n", true, $force);
        $written[] = $this->writeIfAllowed($base . '/output.schema.json', Json::encode($this->schemas->fromFieldDefinition($feature . '_output', (array) $definition['output']), true) . "\n", true, $force);
        $written[] = $this->writeIfAllowed($base . '/action.php', $this->actionTemplate($feature, $canonicalFeature), false, $force);

        $queries = array_values(array_map('strval', (array) (($definition['database']['queries'] ?? []))));
        $written[] = $this->writeIfAllowed($base . '/queries.sql', $this->queries->generate($queries), true, $force);

        $written[] = $this->writeIfAllowed($base . '/permissions.yaml', Yaml::dump([
            'version' => 1,
            'permissions' => array_values(array_map('strval', (array) ($definition['auth']['permissions'] ?? []))),
            'rules' => new \stdClass(),
        ]), true, $force);

        $written[] = $this->writeIfAllowed($base . '/cache.yaml', Yaml::dump([
            'version' => 1,
            'entries' => array_map(static fn(string $key): array => [
                'key' => $key,
                'kind' => 'computed',
                'ttl_seconds' => 300,
                'invalidated_by' => [$canonicalFeature],
            ], array_values(array_map('strval', (array) ($definition['cache']['invalidate'] ?? [])))),
        ]), true, $force);

        $written[] = $this->writeIfAllowed($base . '/events.yaml', Yaml::dump([
            'version' => 1,
            'emit' => array_map(static fn(string $name): array => [
                'name' => $name,
                'schema' => [
                    'type' => 'object',
                    'additionalProperties' => false,
                    'properties' => [],
                ],
            ], array_values(array_map('strval', (array) ($definition['events']['emit'] ?? [])))),
            'subscribe' => [],
        ]), true, $force);

        $written[] = $this->writeIfAllowed($base . '/jobs.yaml', Yaml::dump([
            'version' => 1,
            'dispatch' => array_map(static fn(string $name): array => [
                'name' => $name,
                'input_schema' => [
                    'type' => 'object',
                    'additionalProperties' => false,
                    'properties' => [],
                ],
                'queue' => 'default',
                'retry' => [
                    'max_attempts' => 3,
                    'backoff_seconds' => [1, 5, 30],
                ],
                'timeout_seconds' => 60,
            ], array_values(array_map('strval', (array) ($definition['jobs']['dispatch'] ?? [])))),
        ]), true, $force);

        $written[] = $this->writeIfAllowed($base . '/prompts.md', "# {$feature}\n\nFeature-local LLM notes.\n", false, $force);

        $required = array_values(array_map('strval', (array) ($definition['tests']['required'] ?? ['contract', 'feature', 'auth'])));
        $written = array_merge($written, $this->tests->generate($feature, $base, $required));

        $context = new ContextManifestGenerator($this->paths);
        $written[] = $context->write($canonicalFeature, $manifest);

        return array_values(array_filter($written));
    }

    /**
     * @param array<string,mixed> $definition
     * @return array<string,mixed>
     */
    private function buildFeatureManifest(array $definition, string $canonicalFeature): array
    {
        $manifest = [
            'version' => 2,
            'feature' => $canonicalFeature,
            'kind' => (string) ($definition['kind'] ?? 'http'),
            'description' => (string) ($definition['description'] ?? 'No description.'),
            'owners' => (array) ($definition['owners'] ?? ['platform']),
            'route' => (array) ($definition['route'] ?? []),
            'input' => ['schema' => 'app/features/' . $canonicalFeature . '/input.schema.json'],
            'output' => ['schema' => 'app/features/' . $canonicalFeature . '/output.schema.json'],
            'auth' => (array) ($definition['auth'] ?? ['required' => true, 'strategies' => ['bearer'], 'permissions' => []]),
            'database' => array_merge([
                'reads' => [],
                'writes' => [],
                'transactions' => 'required',
                'queries' => [],
            ], (array) ($definition['database'] ?? [])),
            'cache' => array_merge([
                'reads' => [],
                'writes' => [],
                'invalidate' => [],
            ], (array) ($definition['cache'] ?? [])),
            'events' => array_merge([
                'emit' => [],
                'subscribe' => [],
            ], (array) ($definition['events'] ?? [])),
            'jobs' => array_merge([
                'dispatch' => [],
            ], (array) ($definition['jobs'] ?? [])),
            'rate_limit' => array_merge([
                'strategy' => 'user',
                'bucket' => $canonicalFeature,
                'cost' => 1,
            ], (array) ($definition['rate_limit'] ?? [])),
            'observability' => array_merge([
                'audit' => true,
                'trace' => true,
                'log_level' => 'info',
            ], (array) ($definition['observability'] ?? [])),
            'tests' => array_merge([
                'required' => ['contract', 'feature', 'auth'],
            ], (array) ($definition['tests'] ?? [])),
            'llm' => array_merge([
                'editable' => true,
                'risk_level' => 'medium',
                'notes_file' => 'prompts.md',
            ], (array) ($definition['llm'] ?? [])),
        ];

        foreach (['csrf', 'resource', 'listing', 'uploads', 'ui'] as $key) {
            if (is_array($definition[$key] ?? null)) {
                $manifest[$key] = $definition[$key];
            }
        }

        return $manifest;
    }

    private function actionTemplate(string $feature, string $canonicalFeature): string
    {
        $namespace = 'App\\Features\\' . Str::studly($feature);
        $template = <<<'PHP'
<?php
declare(strict_types=1);

namespace {{NAMESPACE}};

use Foundry\Auth\AuthContext;
use Foundry\Feature\FeatureAction;
use Foundry\Feature\FeatureServices;
use Foundry\Http\RequestContext;

final class Action implements FeatureAction
{
    #[\Override]
    public function handle(array $input, RequestContext $request, AuthContext $auth, FeatureServices $services): array
    {
        return [
            'status' => 'ok',
            'feature' => '{{FEATURE}}',
        ];
    }
}
PHP;

        return str_replace(
            ['{{NAMESPACE}}', '{{FEATURE}}'],
            [$namespace, $canonicalFeature],
            $template,
        );
    }

    private function writeIfAllowed(string $path, string $content, bool $generated, bool $force = false): string
    {
        if (is_file($path)) {
            $existing = file_get_contents($path) ?: '';
            if (!$generated && $existing !== '' && !$force) {
                throw new FoundryError('FILE_EXISTS_NOT_GENERATED', 'io', ['path' => $path], 'Refusing to overwrite non-generated file.');
            }
        }

        if ($generated && str_ends_with($path, '.php') && !str_starts_with($content, '<?php')) {
            $content = $this->phpHeader($path) . $content;
        }

        file_put_contents($path, $content);

        return $path;
    }

    private function phpHeader(string $path): string
    {
        return "<?php\ndeclare(strict_types=1);\n\n/**\n * GENERATED FILE - DO NOT EDIT DIRECTLY\n * Source: {$path}\n * Regenerate with: foundry generate feature\n */\n\n";
    }
}
