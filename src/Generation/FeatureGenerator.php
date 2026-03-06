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
    ) {
    }

    /**
     * @return array<int,string>
     */
    public function generateFromSpec(string $specPath): array
    {
        $spec = Yaml::parseFile($specPath);

        $feature = (string) ($spec['feature'] ?? '');
        if ($feature === '' || !Str::isSnakeCase($feature)) {
            throw new FoundryError('FEATURE_NAME_INVALID', 'validation', ['feature' => $feature], 'Feature name must be snake_case.');
        }

        $kind = (string) ($spec['kind'] ?? 'http');
        $base = $this->paths->join('app/features/' . $feature);
        if (!is_dir($base)) {
            mkdir($base, 0777, true);
        }

        $manifest = $this->buildFeatureManifest($spec);

        $written = [];
        $written[] = $this->writeIfAllowed($base . '/feature.yaml', Yaml::dump($manifest), true);
        $written[] = $this->writeIfAllowed($base . '/input.schema.json', Json::encode($this->schemas->fromFieldSpec($feature . '_input', (array) $spec['input']), true) . "\n", true);
        $written[] = $this->writeIfAllowed($base . '/output.schema.json', Json::encode($this->schemas->fromFieldSpec($feature . '_output', (array) $spec['output']), true) . "\n", true);
        $written[] = $this->writeIfAllowed($base . '/action.php', $this->actionTemplate($feature), false);

        $queries = array_values(array_map('strval', (array) (($spec['database']['queries'] ?? []))));
        $written[] = $this->writeIfAllowed($base . '/queries.sql', $this->queries->generate($queries), true);

        $written[] = $this->writeIfAllowed($base . '/permissions.yaml', Yaml::dump([
            'version' => 1,
            'permissions' => array_values(array_map('strval', (array) ($spec['auth']['permissions'] ?? []))),
            'rules' => new \stdClass(),
        ]), true);

        $written[] = $this->writeIfAllowed($base . '/cache.yaml', Yaml::dump([
            'version' => 1,
            'entries' => array_map(static fn (string $key): array => [
                'key' => $key,
                'kind' => 'computed',
                'ttl_seconds' => 300,
                'invalidated_by' => [$feature],
            ], array_values(array_map('strval', (array) ($spec['cache']['invalidate'] ?? [])))),
        ]), true);

        $written[] = $this->writeIfAllowed($base . '/events.yaml', Yaml::dump([
            'version' => 1,
            'emit' => array_map(static fn (string $name): array => [
                'name' => $name,
                'schema' => [
                    'type' => 'object',
                    'additionalProperties' => false,
                    'properties' => [],
                ],
            ], array_values(array_map('strval', (array) ($spec['events']['emit'] ?? [])))),
            'subscribe' => [],
        ]), true);

        $written[] = $this->writeIfAllowed($base . '/jobs.yaml', Yaml::dump([
            'version' => 1,
            'dispatch' => array_map(static fn (string $name): array => [
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
            ], array_values(array_map('strval', (array) ($spec['jobs']['dispatch'] ?? [])))),
        ]), true);

        $written[] = $this->writeIfAllowed($base . '/prompts.md', "# {$feature}\n\nFeature-local LLM notes.\n", false);

        $required = array_values(array_map('strval', (array) ($spec['tests']['required'] ?? ['contract', 'feature', 'auth'])));
        $written = array_merge($written, $this->tests->generate($feature, $base, $required));

        $context = new ContextManifestGenerator($this->paths);
        $written[] = $context->write($feature, $manifest);

        return array_values(array_filter($written));
    }

    /**
     * @param array<string,mixed> $spec
     * @return array<string,mixed>
     */
    private function buildFeatureManifest(array $spec): array
    {
        return [
            'version' => 1,
            'feature' => (string) $spec['feature'],
            'kind' => (string) ($spec['kind'] ?? 'http'),
            'description' => (string) ($spec['description'] ?? 'No description.'),
            'owners' => (array) ($spec['owners'] ?? ['platform']),
            'route' => (array) ($spec['route'] ?? []),
            'input' => ['schema' => 'app/features/' . $spec['feature'] . '/input.schema.json'],
            'output' => ['schema' => 'app/features/' . $spec['feature'] . '/output.schema.json'],
            'auth' => (array) ($spec['auth'] ?? ['required' => true, 'strategies' => ['bearer'], 'permissions' => []]),
            'database' => array_merge([
                'reads' => [],
                'writes' => [],
                'transactions' => 'required',
                'queries' => [],
            ], (array) ($spec['database'] ?? [])),
            'cache' => array_merge([
                'reads' => [],
                'writes' => [],
                'invalidate' => [],
            ], (array) ($spec['cache'] ?? [])),
            'events' => array_merge([
                'emit' => [],
                'subscribe' => [],
            ], (array) ($spec['events'] ?? [])),
            'jobs' => array_merge([
                'dispatch' => [],
            ], (array) ($spec['jobs'] ?? [])),
            'rate_limit' => array_merge([
                'strategy' => 'user',
                'bucket' => (string) $spec['feature'],
                'cost' => 1,
            ], (array) ($spec['rate_limit'] ?? [])),
            'observability' => array_merge([
                'audit' => true,
                'trace' => true,
                'log_level' => 'info',
            ], (array) ($spec['observability'] ?? [])),
            'tests' => array_merge([
                'required' => ['contract', 'feature', 'auth'],
            ], (array) ($spec['tests'] ?? [])),
            'llm' => array_merge([
                'editable' => true,
                'risk' => 'medium',
                'notes_file' => 'prompts.md',
            ], (array) ($spec['llm'] ?? [])),
        ];
    }

    private function actionTemplate(string $feature): string
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
            [$namespace, $feature],
            $template
        );
    }

    private function writeIfAllowed(string $path, string $content, bool $generated): string
    {
        if (is_file($path)) {
            $existing = file_get_contents($path) ?: '';
            if (!$generated && $existing !== '') {
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
