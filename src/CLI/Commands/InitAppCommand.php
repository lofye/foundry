<?php
declare(strict_types=1);

namespace Foundry\CLI\Commands;

use Foundry\CLI\Command;
use Foundry\CLI\CommandContext;
use Foundry\Generation\ContextManifestGenerator;
use Foundry\Generation\IndexGenerator;
use Foundry\Support\FoundryError;
use Foundry\Support\Json;
use Foundry\Support\Paths;
use Foundry\Support\Yaml;

final class InitAppCommand extends Command
{
    #[\Override]
    public function matches(array $args): bool
    {
        return ($args[0] ?? null) === 'init' && ($args[1] ?? null) === 'app';
    }

    #[\Override]
    public function run(array $args, CommandContext $context): array
    {
        $targetArgument = (string) ($args[2] ?? '');
        if ($targetArgument === '') {
            throw new FoundryError(
                'CLI_INIT_APP_PATH_REQUIRED',
                'validation',
                [],
                'Target path required. Usage: foundry init app <path> [--name=vendor/app] [--version=^0.1] [--force]'
            );
        }

        $targetPath = $this->resolvePath($context->paths()->root(), $targetArgument);
        $force = in_array('--force', $args, true);
        $projectName = $this->parseOption($args, '--name') ?? $this->defaultProjectName($targetPath);
        $frameworkVersion = $this->parseOption($args, '--version') ?? '^0.1';

        $this->prepareTargetDirectory($targetPath, $force);
        $files = $this->writeScaffold($targetPath, $projectName, $frameworkVersion);

        $paths = new Paths($targetPath, $context->paths()->frameworkRoot());
        $generated = (new IndexGenerator($paths))->generate();
        $manifest = Yaml::parseFile($paths->join('app/features/home/feature.yaml'));
        $contextFile = (new ContextManifestGenerator($paths))->write('home', $manifest);

        return [
            'status' => 0,
            'message' => 'Foundry app scaffolded.',
            'payload' => [
                'project_root' => $targetPath,
                'project_name' => $projectName,
                'framework_package' => 'lofye/foundry',
                'framework_version' => $frameworkVersion,
                'files_written' => array_values(array_merge($files, $generated, [$contextFile])),
                'next_steps' => [
                    'cd ' . $targetPath,
                    'composer install',
                    'php vendor/bin/foundry compile graph --json',
                    'php vendor/bin/foundry verify graph --json',
                    'php vendor/bin/foundry verify contracts --json',
                    'php -S 127.0.0.1:8000 app/platform/public/index.php',
                ],
            ],
        ];
    }

    /**
     * @return array<int,string>
     */
    private function writeScaffold(string $targetPath, string $projectName, string $frameworkVersion): array
    {
        $placeholders = [
            '{{PROJECT_NAME}}' => $projectName,
        ];

        $composer = [
            'name' => $projectName,
            'description' => 'Application built on Foundry framework.',
            'type' => 'project',
            'require' => [
                'php' => '^8.4',
                'lofye/foundry' => $frameworkVersion,
            ],
            'require-dev' => [
                'phpunit/phpunit' => '^11.5',
            ],
            'autoload' => [
                'psr-4' => [
                    'App\\' => 'app/',
                ],
            ],
            'scripts' => [
                'foundry:generate' => 'php vendor/bin/foundry compile graph --json',
                'foundry:verify' => 'php vendor/bin/foundry verify graph --json && php vendor/bin/foundry verify contracts --json',
                'test' => 'php vendor/bin/phpunit',
            ],
            'minimum-stability' => 'dev',
            'prefer-stable' => true,
        ];

        $files = [
            '.gitignore' => "/vendor/\n/.phpunit.cache/\n",
            '.env.example' => "APP_ENV=local\nAPP_DEBUG=1\n",
            'README.md' => $this->replace(<<<'MD'
# {{PROJECT_NAME}}

This app was scaffolded with Foundry.

## First Run
```bash
composer install
php vendor/bin/foundry compile graph --json
php vendor/bin/foundry verify graph --json
php vendor/bin/foundry verify contracts --json
php -S 127.0.0.1:8000 app/platform/public/index.php
```

## LLM Workflow
```bash
php vendor/bin/foundry inspect feature home --json
php vendor/bin/foundry generate feature <spec.yaml> --json
php vendor/bin/foundry compile graph --json
php vendor/bin/foundry verify graph --json
php vendor/bin/foundry verify contracts --json
```

## Upgrading Foundry
```bash
composer update lofye/foundry
php vendor/bin/foundry compile graph --json
php vendor/bin/foundry verify graph --json
php vendor/bin/foundry verify contracts --json
```
MD, $placeholders),
            'composer.json' => Json::encode($composer, true) . "\n",
            'app/platform/public/index.php' => <<<'PHP'
<?php
declare(strict_types=1);

use Foundry\Core\RuntimeFactory;
use Foundry\Http\ResponseEmitter;
use Foundry\Support\Paths;

$projectRoot = dirname(__DIR__, 3);
require $projectRoot . '/vendor/autoload.php';

$paths = Paths::fromCwd($projectRoot);
$kernel = RuntimeFactory::httpKernel($paths);
$request = RuntimeFactory::requestFromGlobals();
$response = $kernel->handle($request);

echo (new ResponseEmitter())->emit($response);
PHP
                ,
            'app/features/home/feature.yaml' => <<<'YAML'
version: 1
feature: home
kind: http
description: Home endpoint for the web app.
owners: [platform]
route:
  method: GET
  path: /
input:
  schema: app/features/home/input.schema.json
output:
  schema: app/features/home/output.schema.json
auth:
  required: false
  strategies: []
  permissions: []
database:
  reads: []
  writes: []
  transactions: required
  queries: []
cache:
  reads: []
  writes: []
  invalidate: []
events:
  emit: []
  subscribe: []
jobs:
  dispatch: []
rate_limit:
  strategy: user
  bucket: home
  cost: 1
observability:
  audit: true
  trace: true
  log_level: info
tests:
  required: [contract, feature, auth]
llm:
  editable: true
  risk: low
  notes_file: prompts.md
YAML
                ,
            'app/features/home/input.schema.json' => Json::encode([
                '$schema' => 'https://json-schema.org/draft/2020-12/schema',
                'type' => 'object',
                'additionalProperties' => false,
                'properties' => new \stdClass(),
            ], true) . "\n",
            'app/features/home/output.schema.json' => Json::encode([
                '$schema' => 'https://json-schema.org/draft/2020-12/schema',
                'type' => 'object',
                'additionalProperties' => false,
                'required' => ['status', 'framework', 'message'],
                'properties' => [
                    'status' => ['type' => 'string'],
                    'framework' => ['type' => 'string'],
                    'message' => ['type' => 'string'],
                    'links' => [
                        'type' => 'object',
                        'additionalProperties' => false,
                        'properties' => [
                            'docs' => ['type' => 'string'],
                            'quickstart' => ['type' => 'string'],
                        ],
                    ],
                ],
            ], true) . "\n",
            'app/features/home/action.php' => <<<'PHP'
<?php
declare(strict_types=1);

namespace App\Features\Home;

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
            'framework' => 'foundry',
            'message' => 'Edit app/features/home to build your site.',
            'links' => [
                'docs' => '/docs',
                'quickstart' => '/quickstart',
            ],
        ];
    }
}
PHP
                ,
            'app/features/home/cache.yaml' => "version: 1\nentries: []\n",
            'app/features/home/events.yaml' => "version: 1\nemit: []\nsubscribe: []\n",
            'app/features/home/jobs.yaml' => "version: 1\ndispatch: []\n",
            'app/features/home/permissions.yaml' => "version: 1\npermissions: []\nrules: {}\n",
            'app/features/home/prompts.md' => "# home\n\nFeature-local notes for LLM edits.\n",
            'app/features/home/tests/home_contract_test.php' => "<?php\ndeclare(strict_types=1);\n",
            'app/features/home/tests/home_feature_test.php' => "<?php\ndeclare(strict_types=1);\n",
            'app/features/home/tests/home_auth_test.php' => "<?php\ndeclare(strict_types=1);\n",
        ];

        $written = [];
        foreach ($files as $relativePath => $content) {
            $absolute = rtrim($targetPath, '/') . '/' . ltrim($relativePath, '/');
            $dir = dirname($absolute);
            if (!is_dir($dir)) {
                mkdir($dir, 0777, true);
            }

            file_put_contents($absolute, $content);
            $written[] = $absolute;
        }

        return $written;
    }

    private function prepareTargetDirectory(string $targetPath, bool $force): void
    {
        if (!is_dir($targetPath)) {
            mkdir($targetPath, 0777, true);

            return;
        }

        $items = scandir($targetPath);
        if ($items === false) {
            throw new FoundryError('CLI_INIT_APP_TARGET_UNREADABLE', 'io', ['path' => $targetPath], 'Unable to read target directory.');
        }

        $nonDotItems = array_values(array_filter($items, static fn (string $item): bool => $item !== '.' && $item !== '..'));
        if ($nonDotItems !== [] && !$force) {
            throw new FoundryError(
                'CLI_INIT_APP_TARGET_NOT_EMPTY',
                'validation',
                ['path' => $targetPath],
                'Target directory is not empty. Use --force to scaffold anyway.'
            );
        }
    }

    private function resolvePath(string $cwd, string $path): string
    {
        if (str_starts_with($path, '/')) {
            return rtrim($path, '/');
        }

        return rtrim($cwd, '/') . '/' . ltrim($path, '/');
    }

    private function defaultProjectName(string $targetPath): string
    {
        $base = basename(rtrim($targetPath, '/'));
        $slug = strtolower((string) preg_replace('/[^a-z0-9-]+/', '-', $base));
        $slug = trim($slug, '-');
        if ($slug === '') {
            $slug = 'foundry-app';
        }

        return 'acme/' . $slug;
    }

    private function parseOption(array $args, string $option): ?string
    {
        foreach ($args as $index => $arg) {
            if (str_starts_with($arg, $option . '=')) {
                $value = substr($arg, strlen($option . '='));

                return $value !== '' ? $value : null;
            }

            if ($arg === $option) {
                $value = (string) ($args[$index + 1] ?? '');

                return $value !== '' ? $value : null;
            }
        }

        return null;
    }

    /**
     * @param array<string,string> $replacements
     */
    private function replace(string $template, array $replacements): string
    {
        return str_replace(array_keys($replacements), array_values($replacements), $template);
    }
}
