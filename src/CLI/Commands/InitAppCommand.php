<?php
declare(strict_types=1);

namespace Foundry\CLI\Commands;

use Foundry\CLI\Command;
use Foundry\CLI\CommandContext;
use Foundry\Compiler\CompileOptions;
use Foundry\Compiler\GraphCompiler;
use Foundry\Documentation\GraphDocsGenerator;
use Foundry\Documentation\InspectUiGenerator;
use Foundry\Generation\FeatureGenerator;
use Foundry\Support\ApiSurfaceRegistry;
use Foundry\Support\FoundryError;
use Foundry\Support\Json;
use Foundry\Support\Paths;

final class InitAppCommand extends Command
{
    #[\Override]
    public function matches(array $args): bool
    {
        return ($args[0] ?? null) === 'new'
            || (($args[0] ?? null) === 'init' && ($args[1] ?? null) === 'app');
    }

    #[\Override]
    public function run(array $args, CommandContext $context): array
    {
        $usingNewAlias = ($args[0] ?? null) === 'new';
        $targetIndex = $usingNewAlias ? 1 : 2;
        $targetArgument = (string) ($args[$targetIndex] ?? '');
        if ($usingNewAlias && ($targetArgument === '' || str_starts_with($targetArgument, '--'))) {
            $targetArgument = '.';
        }

        if ($targetArgument === '' || str_starts_with($targetArgument, '--')) {
            throw new FoundryError(
                'CLI_INIT_APP_PATH_REQUIRED',
                'validation',
                [],
                'Target path required. Usage: ' . $this->usage($usingNewAlias),
            );
        }

        $targetPath = $this->resolvePath($context->paths()->root(), $targetArgument);
        $force = in_array('--force', $args, true);
        $starterMode = $this->parseStarterMode($args);
        $existingComposer = $this->loadComposerConfig($targetPath);
        $projectName = $this->parseOption($args, '--name')
            ?? $this->composerName($existingComposer)
            ?? $this->defaultProjectName($targetPath);
        $frameworkVersion = $this->parseOption($args, '--version')
            ?? $this->composerFrameworkVersion($existingComposer)
            ?? '^0.1';
        $frameworkRoot = $context->paths()->frameworkRoot();

        $this->prepareTargetDirectory($targetPath, $force);

        $paths = new Paths($targetPath, $frameworkRoot);
        $scaffold = $this->writeScaffold(
            $paths,
            $projectName,
            $frameworkVersion,
            $starterMode,
            $this->displayName($targetPath),
            $force,
        );

        $compiler = new GraphCompiler($paths);
        $compile = $compiler->compile(new CompileOptions());
        $docs = (new GraphDocsGenerator($paths, new ApiSurfaceRegistry()))->generate($compile->graph, 'markdown');
        $inspectUi = (new InspectUiGenerator($paths))->generate($compile->graph);

        $filesWritten = array_values(array_unique(array_merge(
            $scaffold['files'],
            array_values(array_map('strval', $compile->writtenFiles)),
            array_values(array_map('strval', (array) ($docs['files'] ?? []))),
            array_values(array_map('strval', (array) ($inspectUi['files'] ?? []))),
        )));
        sort($filesWritten);

        $payload = [
            'project_root' => $targetPath,
            'project_name' => $projectName,
            'framework_package' => 'lofye/foundry-framework',
            'framework_version' => $frameworkVersion,
            'starter_mode' => $starterMode,
            'starter_label' => $this->starterLabel($starterMode),
            'features' => $scaffold['features'],
            'routes' => $scaffold['routes'],
            'files_written' => $filesWritten,
            'compile_diagnostics_summary' => $compile->diagnostics->summary(),
            'docs_directory' => (string) ($docs['directory'] ?? ''),
            'inspect_ui_root' => (string) ($inspectUi['root'] ?? ''),
            'next_steps' => $this->nextSteps($targetPath),
        ];

        return [
            'status' => 0,
            'message' => $context->expectsJson() ? 'Foundry app scaffolded.' : $this->renderHumanMessage($payload),
            'payload' => $context->expectsJson() ? $payload : null,
        ];
    }

    /**
     * @return array{files:array<int,string>,features:array<int,string>,routes:array<int,string>}
     */
    private function writeScaffold(
        Paths $paths,
        string $projectName,
        string $frameworkVersion,
        string $starterMode,
        string $displayName,
        bool $force,
    ): array {
        $featureSpecs = $this->starterFeatureSpecs($starterMode);
        $protectedSmokeRoute = $this->protectedSmokeRoute($starterMode);

        $written = $this->writeProjectFiles(
            $paths,
            $projectName,
            $frameworkVersion,
            $starterMode,
            $displayName,
            $featureSpecs,
            $protectedSmokeRoute,
        );

        $features = [];
        $routes = [];
        $generator = new FeatureGenerator($paths);

        foreach ($featureSpecs as $spec) {
            $definition = (array) ($spec['definition'] ?? []);
            $feature = (string) ($definition['feature'] ?? '');
            if ($feature === '') {
                continue;
            }

            foreach ($generator->generateFromArray($definition, $force) as $path) {
                $written[] = $path;
            }

            $actionPath = $paths->join('app/features/' . $feature . '/action.php');
            file_put_contents($actionPath, (string) ($spec['action'] ?? ''));
            $written[] = $actionPath;

            $features[] = $feature;
            $routes[] = sprintf(
                '%s %s',
                strtoupper((string) ($definition['route']['method'] ?? 'GET')),
                (string) ($definition['route']['path'] ?? '/')
            );
        }

        $written = array_values(array_unique(array_map('strval', $written)));
        $features = array_values(array_unique(array_map('strval', $features)));
        $routes = array_values(array_unique(array_map('strval', $routes)));

        sort($written);
        sort($features);
        sort($routes);

        return [
            'files' => $written,
            'features' => $features,
            'routes' => $routes,
        ];
    }

    /**
     * @param array<int,array<string,mixed>> $featureSpecs
     * @param array<string,string>|null $protectedSmokeRoute
     * @return array<int,string>
     */
    private function writeProjectFiles(
        Paths $paths,
        string $projectName,
        string $frameworkVersion,
        string $starterMode,
        string $displayName,
        array $featureSpecs,
        ?array $protectedSmokeRoute,
    ): array {
        $placeholders = [
            '{{PROJECT_NAME}}' => $projectName,
            '{{DISPLAY_NAME}}' => $displayName,
            '{{STARTER_MODE}}' => $starterMode,
            '{{STARTER_LABEL}}' => $this->starterLabel($starterMode),
            '{{STARTER_SUMMARY}}' => $this->starterSummary($starterMode),
            '{{AUTH_HINT}}' => $this->starterAuthHint($starterMode),
            '{{ROUTE_SUMMARY}}' => $this->routeSummaryMarkdown($featureSpecs),
        ];

        $composer = [
            'name' => $projectName,
            'description' => 'Application built on Foundry framework.',
            'type' => 'project',
            'require' => [
                'php' => '^8.4',
                'lofye/foundry-framework' => $frameworkVersion,
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
                'foundry:compile' => '@php foundry compile graph --json',
                'foundry:inspect' => '@php foundry inspect graph --json && @php foundry inspect pipeline --json',
                'foundry:doctor' => '@php foundry doctor --json',
                'foundry:docs' => '@php foundry generate docs --format=markdown --json && @php foundry generate inspect-ui --json',
                'foundry:verify' => '@php foundry verify graph --json && @php foundry verify pipeline --json && @php foundry verify contracts --json',
                'serve' => 'php -S 127.0.0.1:8000 public/index.php',
                'test' => 'php vendor/bin/phpunit -c phpunit.xml.dist',
            ],
            'minimum-stability' => 'dev',
            'prefer-stable' => true,
        ];
        $composer = $this->mergedComposerConfig($paths, $composer);

        $files = [
            '.gitignore' => <<<'TXT'
/vendor/
/.phpunit.cache/
/.env
/docs/generated/*
!/docs/generated/.gitignore
/docs/inspect-ui/*
!/docs/inspect-ui/.gitignore
/app/.foundry/build/
/database/*.sqlite
/storage/files/*
!/storage/files/.gitignore
/storage/logs/*
!/storage/logs/.gitignore
/storage/tmp/*
!/storage/tmp/.gitignore
TXT
            ,
            '.env.example' => $this->replace(<<<'ENV'
APP_NAME="{{DISPLAY_NAME}}"
APP_ENV=local
APP_DEBUG=1
FOUNDRY_AUTH_HEADER=x-user-id
ENV, $placeholders)
            ,
            'AGENTS.md' => <<<'MD'
# Foundry App Agent Guide

Use this file when working inside a Foundry application repository.

## Command Rule

- In Foundry app repos, prefer `foundry ...`
- If your shell does not resolve current-directory executables, use `./foundry ...`
- Prefer `--json` for inspect, verify, doctor, prompt, export, and generation commands when an agent is consuming the output

## Source Of Truth

- Treat `app/features/*` as source-of-truth application behavior
- Treat `app/definitions/*` as source-of-truth definitions when that folder exists
- Treat `app/.foundry/build/*` as canonical compiled output
- Treat `app/generated/*` as generated compatibility projections
- Treat `docs/generated/*` and `docs/inspect-ui/*` as generated documentation output
- Do not hand-edit `app/generated/*`; regenerate instead

## Safe Edit Loop

1. Inspect current feature and graph reality before editing.
2. Edit the smallest source-of-truth files that satisfy the task.
3. Compile graph and inspect diagnostics.
4. Inspect impact, pipeline, and route surfaces when the change touches auth, routes, docs, or execution order.
5. Verify graph and contract surfaces.
6. Refresh generated docs if source-of-truth changed.
7. Run PHPUnit.

## Guard Rails

- When a bug is encountered, create a test that fails because of that bug, then modify the non-test code so that the test passes while maintaining the intent of the original code.
- Never take a shortcut (such as forcing a test falsely return true) to get a test to pass.
- Keep test coverage above 90% for all new features and existing code.

Recommended command loop:

```bash
foundry inspect graph --json
foundry inspect pipeline --json
foundry inspect feature <feature> --json
foundry inspect context <feature> --json
foundry compile graph --json
foundry inspect impact --file=app/features/<feature>/feature.yaml --json
foundry doctor --feature=<feature> --json
foundry generate docs --format=markdown --json
foundry generate inspect-ui --json
foundry verify graph --json
foundry verify pipeline --json
foundry verify contracts --json
php vendor/bin/phpunit -c phpunit.xml.dist
```

## App Rules

- Keep changes feature-local unless the task is explicitly cross-cutting platform work
- Update feature tests and calling code together when contracts or schemas change
- Preserve explicit manifests, schemas, and context files; avoid hidden behavior
- Use feature-local `prompts.md` and `context.manifest.json` when present to understand the feature before editing

## Ask First

Stop and ask before:
- hand-editing generated files
- changing app-wide conventions, package dependencies, or generated scaffold structure without approval
- making a behavior choice when the requested behavior is ambiguous or conflicts with the existing feature contract
MD
            ,
            'README.md' => $this->replace(<<<'MD'
# {{DISPLAY_NAME}}

This Foundry project was scaffolded in `{{STARTER_LABEL}}` mode.

{{STARTER_SUMMARY}}

## Working With LLMs

Start with `AGENTS.md`. It defines the repo-local workflow and command rules for AI assistants working in this app.

## First Run

Foundry scaffolds a project-local `foundry` launcher. If your shell does not resolve current-directory executables, use `./foundry ...` instead.

```bash
composer install
foundry compile graph --json
foundry inspect graph --json
foundry inspect pipeline --json
foundry doctor --json
foundry generate docs --format=markdown --json
foundry generate inspect-ui --json
foundry verify graph --json
foundry verify pipeline --json
foundry verify contracts --json
php vendor/bin/phpunit -c phpunit.xml.dist
php -S 127.0.0.1:8000 public/index.php
```

## Starter Routes

{{ROUTE_SUMMARY}}

## Inspectability

- Generated graph docs: `docs/generated`
- Generated inspect UI: `docs/inspect-ui`
- Source definition example: `app/definitions/inspect-ui/dev.inspect-ui.yaml`
- {{AUTH_HINT}}
MD, $placeholders),
            'composer.json' => Json::encode($composer, true) . "\n",
            'foundry' => <<<'PHP'
#!/usr/bin/env php
<?php
declare(strict_types=1);

$binary = __DIR__ . '/vendor/bin/foundry';
if (!is_file($binary)) {
    fwrite(STDERR, "Foundry dependencies are not installed. Missing vendor/bin/foundry. Run composer install first.\n");
    exit(1);
}

require $binary;
PHP
            ,
            'foundry.bat' => <<<'BAT'
@ECHO OFF
SETLOCAL
IF EXIST "%~dp0vendor\bin\foundry.bat" (
  CALL "%~dp0vendor\bin\foundry.bat" %*
  EXIT /B %ERRORLEVEL%
)
IF EXIST "%~dp0vendor\bin\foundry" (
  php "%~dp0vendor\bin\foundry" %*
  EXIT /B %ERRORLEVEL%
)
ECHO Foundry dependencies are not installed. Missing vendor\bin\foundry. Run composer install first. 1>&2
EXIT /B 1
BAT
            ,
            'phpunit.xml.dist' => <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<phpunit bootstrap="vendor/autoload.php"
         colors="true"
         cacheDirectory=".phpunit.cache"
         beStrictAboutOutputDuringTests="true"
         beStrictAboutTestsThatDoNotTestAnything="true"
         failOnRisky="true"
         failOnWarning="true">
    <testsuites>
        <testsuite name="feature">
            <directory suffix="_test.php">app/features</directory>
        </testsuite>
        <testsuite name="smoke">
            <directory suffix="Test.php">tests</directory>
        </testsuite>
    </testsuites>
    <source>
        <include>
            <directory>app</directory>
            <directory>tests</directory>
        </include>
    </source>
</phpunit>
XML
            ,
            'tests/Smoke/AppBootTest.php' => $this->appBootTestTemplate($starterMode, $protectedSmokeRoute),
            'docs/README.md' => $this->replace(<<<'MD'
# Project Docs

This starter already generated graph-derived docs and inspect pages so a new project can be explored immediately.

## Generated Outputs

- `docs/generated/features.md`
- `docs/generated/routes.md`
- `docs/generated/cli-reference.md`
- `docs/inspect-ui/index.html`

## Refresh

```bash
foundry generate docs --format=markdown --json
foundry generate inspect-ui --json
```

## Source-Of-Truth Inspectability Seed

Use `app/definitions/inspect-ui/dev.inspect-ui.yaml` as the starter definition for inspectability-oriented tooling in this app.

{{AUTH_HINT}}
MD, $placeholders),
            'docs/generated/.gitignore' => "*\n!.gitignore\n",
            'docs/inspect-ui/.gitignore' => "*\n!.gitignore\n",
            'public/index.php' => <<<'PHP'
<?php
declare(strict_types=1);

use Foundry\Core\RuntimeFactory;
use Foundry\Http\ResponseEmitter;
use Foundry\Support\Paths;

$projectRoot = dirname(__DIR__);
require $projectRoot . '/vendor/autoload.php';

$paths = Paths::fromCwd($projectRoot);
$kernel = RuntimeFactory::httpKernel($paths);
$request = RuntimeFactory::requestFromGlobals();
$response = $kernel->handle($request);

echo (new ResponseEmitter())->emit($response);
PHP
            ,
            'bootstrap/app.php' => $this->replace(<<<'PHP'
<?php
declare(strict_types=1);

return [
    'name' => '{{DISPLAY_NAME}}',
    'env' => 'local',
    'debug' => true,
    'starter' => '{{STARTER_MODE}}',
];
PHP, $placeholders),
            'bootstrap/providers.php' => "<?php\ndeclare(strict_types=1);\n\nreturn [];\n",
            'config/app.php' => $this->replace(<<<'PHP'
<?php
declare(strict_types=1);

return [
    'name' => '{{DISPLAY_NAME}}',
    'starter' => '{{STARTER_MODE}}',
];
PHP, $placeholders),
            'config/auth.php' => <<<'PHP'
<?php
declare(strict_types=1);

return [
    'default' => 'bearer',
    'development_header' => 'x-user-id',
    'strategies' => [
        'bearer' => [
            'header' => 'x-user-id',
        ],
    ],
];
PHP
            ,
            'config/database.php' => <<<'PHP'
<?php
declare(strict_types=1);

return [
    'default' => 'sqlite',
    'connections' => [
        'sqlite' => [
            'dsn' => 'sqlite:database/foundry.sqlite',
        ],
    ],
];
PHP
            ,
            'config/cache.php' => <<<'PHP'
<?php
declare(strict_types=1);

return [
    'default' => 'array',
];
PHP
            ,
            'config/queue.php' => <<<'PHP'
<?php
declare(strict_types=1);

return [
    'default' => 'sync',
];
PHP
            ,
            'config/storage.php' => <<<'PHP'
<?php
declare(strict_types=1);

return [
    'default' => 'local',
    'root' => 'storage/files',
];
PHP
            ,
            'config/ai.php' => <<<'PHP'
<?php
declare(strict_types=1);

return [
    'default' => 'static',
];
PHP
            ,
            'config/foundry/extensions.php' => "<?php\ndeclare(strict_types=1);\n\nreturn [];\n",
            'database/migrations/.gitignore' => "*\n!.gitignore\n",
            'lang/en/messages.php' => $this->replace(<<<'PHP'
<?php
declare(strict_types=1);

return [
    'app.title' => '{{DISPLAY_NAME}}',
];
PHP, $placeholders),
            'storage/files/.gitignore' => "*\n!.gitignore\n",
            'storage/logs/.gitignore' => "*\n!.gitignore\n",
            'storage/tmp/.gitignore' => "*\n!.gitignore\n",
            'app/definitions/inspect-ui/dev.inspect-ui.yaml' => <<<'YAML'
version: 1
name: dev
enabled: true
base_path: /dev/inspect
require_auth: false
sections: [features, routes, schemas, auth, jobs, events, caches, contexts]
YAML
            ,
        ];

        $written = [];
        foreach ($files as $relativePath => $content) {
            $absolute = $paths->join($relativePath);
            $dir = dirname($absolute);
            if (!is_dir($dir)) {
                mkdir($dir, 0777, true);
            }

            file_put_contents($absolute, $content);
            if ($relativePath === 'foundry') {
                @chmod($absolute, 0755);
            }
            $written[] = $absolute;
        }

        sort($written);

        return $written;
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function starterFeatureSpecs(string $starterMode): array
    {
        $specs = [
            [
                'definition' => $this->httpFeatureDefinition(
                    'home',
                    'GET',
                    '/',
                    'Starter landing route for the generated Foundry project.',
                    [],
                    [
                        'status' => ['type' => 'string', 'required' => true],
                        'framework' => ['type' => 'string', 'required' => true],
                        'starter' => ['type' => 'string', 'required' => true],
                        'message' => ['type' => 'string', 'required' => true],
                        'next_route' => ['type' => 'string', 'required' => true],
                    ],
                ),
                'action' => $this->homeActionTemplate($starterMode),
            ],
            [
                'definition' => $this->httpFeatureDefinition(
                    'project_docs',
                    'GET',
                    '/docs',
                    'Inspectability guide for the generated project.',
                    [],
                    [
                        'status' => ['type' => 'string', 'required' => true],
                        'docs_directory' => ['type' => 'string', 'required' => true],
                        'inspect_ui_directory' => ['type' => 'string', 'required' => true],
                        'next_command' => ['type' => 'string', 'required' => true],
                        'message' => ['type' => 'string', 'required' => true],
                    ],
                ),
                'action' => $this->docsActionTemplate(),
            ],
        ];

        return match ($starterMode) {
            'minimal' => array_merge($specs, [
                [
                    'definition' => $this->httpFeatureDefinition(
                        'submit_feedback',
                        'POST',
                        '/feedback',
                        'Public POST route that demonstrates request validation and CSRF-aware pipeline setup.',
                        [
                            'name' => ['type' => 'string', 'required' => true, 'form' => 'text'],
                            'email' => ['type' => 'string', 'required' => true, 'form' => 'email'],
                            'message' => ['type' => 'string', 'required' => true, 'form' => 'textarea'],
                        ],
                        [
                            'status' => ['type' => 'string', 'required' => true],
                            'received_name' => ['type' => 'string', 'required' => true],
                            'message' => ['type' => 'string', 'required' => true],
                        ],
                        csrfRequired: true,
                    ),
                    'action' => $this->feedbackActionTemplate(),
                ],
            ]),
            'standard' => array_merge($specs, [
                [
                    'definition' => $this->httpFeatureDefinition(
                        'submit_feedback',
                        'POST',
                        '/feedback',
                        'Public POST route that demonstrates validation, CSRF, and inspectable pipeline guards.',
                        [
                            'name' => ['type' => 'string', 'required' => true, 'form' => 'text'],
                            'email' => ['type' => 'string', 'required' => true, 'form' => 'email'],
                            'message' => ['type' => 'string', 'required' => true, 'form' => 'textarea'],
                        ],
                        [
                            'status' => ['type' => 'string', 'required' => true],
                            'received_name' => ['type' => 'string', 'required' => true],
                            'message' => ['type' => 'string', 'required' => true],
                        ],
                        csrfRequired: true,
                    ),
                    'action' => $this->feedbackActionTemplate(),
                ],
                [
                    'definition' => $this->httpFeatureDefinition(
                        'dashboard',
                        'GET',
                        '/dashboard',
                        'Protected route that demonstrates bearer auth and permissions in the starter app.',
                        [],
                        [
                            'status' => ['type' => 'string', 'required' => true],
                            'user_id' => ['type' => 'string', 'required' => true],
                            'message' => ['type' => 'string', 'required' => true],
                        ],
                        authRequired: true,
                        strategies: ['bearer'],
                        permissions: ['app.dashboard.view'],
                    ),
                    'action' => $this->dashboardActionTemplate(),
                ],
                [
                    'definition' => $this->httpFeatureDefinition(
                        'current_user',
                        'GET',
                        '/me',
                        'Protected profile route that confirms placeholder auth wiring.',
                        [],
                        [
                            'status' => ['type' => 'string', 'required' => true],
                            'user_id' => ['type' => 'string', 'required' => true],
                            'message' => ['type' => 'string', 'required' => true],
                        ],
                        authRequired: true,
                        strategies: ['bearer'],
                        permissions: ['app.profile.view'],
                    ),
                    'action' => $this->currentUserActionTemplate(),
                ],
            ]),
            'api-first' => array_merge($specs, [
                [
                    'definition' => $this->httpFeatureDefinition(
                        'api_overview',
                        'GET',
                        '/api',
                        'Public API overview route for the generated API-first app.',
                        [],
                        [
                            'status' => ['type' => 'string', 'required' => true],
                            'starter' => ['type' => 'string', 'required' => true],
                            'auth_header' => ['type' => 'string', 'required' => true],
                            'message' => ['type' => 'string', 'required' => true],
                        ],
                    ),
                    'action' => $this->apiOverviewActionTemplate(),
                ],
                [
                    'definition' => $this->httpFeatureDefinition(
                        'api_echo',
                        'POST',
                        '/api/echo',
                        'Public API route that demonstrates request validation in an API-first starter.',
                        [
                            'message' => ['type' => 'string', 'required' => true],
                        ],
                        [
                            'status' => ['type' => 'string', 'required' => true],
                            'echoed_message' => ['type' => 'string', 'required' => true],
                            'message' => ['type' => 'string', 'required' => true],
                        ],
                    ),
                    'action' => $this->apiEchoActionTemplate(),
                ],
                [
                    'definition' => $this->httpFeatureDefinition(
                        'api_me',
                        'GET',
                        '/api/me',
                        'Protected API route that demonstrates placeholder bearer auth.',
                        [],
                        [
                            'status' => ['type' => 'string', 'required' => true],
                            'user_id' => ['type' => 'string', 'required' => true],
                            'auth_strategy' => ['type' => 'string', 'required' => true],
                            'message' => ['type' => 'string', 'required' => true],
                        ],
                        authRequired: true,
                        strategies: ['bearer'],
                        permissions: ['api.me.view'],
                    ),
                    'action' => $this->apiMeActionTemplate(),
                ],
            ]),
            default => [],
        };
    }

    /**
     * @param array<string,array<string,mixed>> $inputFields
     * @param array<string,array<string,mixed>> $outputFields
     * @param array<int,string> $strategies
     * @param array<int,string> $permissions
     * @return array<string,mixed>
     */
    private function httpFeatureDefinition(
        string $feature,
        string $method,
        string $path,
        string $description,
        array $inputFields,
        array $outputFields,
        bool $authRequired = false,
        array $strategies = [],
        array $permissions = [],
        bool $csrfRequired = false,
    ): array {
        return [
            'feature' => $feature,
            'kind' => 'http',
            'description' => $description,
            'owners' => ['platform'],
            'route' => [
                'method' => $method,
                'path' => $path,
            ],
            'input' => [
                'fields' => $inputFields,
            ],
            'output' => [
                'fields' => $outputFields,
            ],
            'auth' => [
                'required' => $authRequired,
                'public' => !$authRequired,
                'strategies' => $strategies,
                'permissions' => $permissions,
            ],
            'csrf' => [
                'required' => $csrfRequired,
            ],
            'database' => [
                'reads' => [],
                'writes' => [],
                'transactions' => 'required',
                'queries' => [],
            ],
            'cache' => [
                'reads' => [],
                'writes' => [],
                'invalidate' => [],
            ],
            'events' => [
                'emit' => [],
                'subscribe' => [],
            ],
            'jobs' => [
                'dispatch' => [],
            ],
            'rate_limit' => [
                'strategy' => $authRequired ? 'user' : 'ip',
                'bucket' => $feature,
                'cost' => 1,
            ],
            'tests' => [
                'required' => ['contract', 'feature', 'auth'],
            ],
            'ui' => [
                'flash_messages' => true,
                'error_page_pattern' => true,
            ],
            'llm' => [
                'editable' => true,
                'risk_level' => 'low',
                'notes_file' => 'prompts.md',
            ],
        ];
    }

    /**
     * @return array<string,string>|null
     */
    private function protectedSmokeRoute(string $starterMode): ?array
    {
        return match ($starterMode) {
            'standard' => ['method' => 'GET', 'path' => '/dashboard'],
            'api-first' => ['method' => 'GET', 'path' => '/api/me'],
            default => null,
        };
    }

    /**
     * @param array<string,string>|null $protectedSmokeRoute
     */
    private function appBootTestTemplate(string $starterMode, ?array $protectedSmokeRoute): string
    {
        $protectedTest = '';

        if (is_array($protectedSmokeRoute)) {
            $method = strtoupper((string) ($protectedSmokeRoute['method'] ?? 'GET'));
            $path = (string) ($protectedSmokeRoute['path'] ?? '/');
            $protectedTest = <<<PHP

    public function test_protected_example_route_accepts_development_header_auth(): void
    {
        \$kernel = RuntimeFactory::httpKernel(new Paths(\$this->projectRoot()));
        \$response = \$kernel->handle(new RequestContext('{$method}', '{$path}', ['x-user-id' => 'demo-user']));

        self::assertSame(200, \$response['status']);
        self::assertSame('demo-user', \$response['body']['user_id']);
    }
PHP;
        }

        $template = <<<PHP
<?php
declare(strict_types=1);

use Foundry\Core\RuntimeFactory;
use Foundry\Http\RequestContext;
use Foundry\Support\Paths;
use PHPUnit\Framework\TestCase;

final class AppBootTest extends TestCase
{
    public function test_home_route_boots_from_generated_indexes(): void
    {
        \$kernel = RuntimeFactory::httpKernel(new Paths(\$this->projectRoot()));
        \$response = \$kernel->handle(new RequestContext('GET', '/'));

        self::assertSame(200, \$response['status']);
        self::assertSame('ok', \$response['body']['status']);
        self::assertSame('{$starterMode}', \$response['body']['starter']);
    }{$protectedTest}

    private function projectRoot(): string
    {
        return dirname(__DIR__, 2);
    }
}
PHP;

        return $template;
    }

    private function homeActionTemplate(string $starterMode): string
    {
        return $this->actionTemplate('home', <<<PHP
        return [
            'status' => 'ok',
            'framework' => 'foundry',
            'starter' => '{$starterMode}',
            'message' => 'Edit app/features to build your app and inspect /docs to explore the generated Foundry surfaces.',
            'next_route' => '/docs',
        ];
PHP);
    }

    private function docsActionTemplate(): string
    {
        return $this->actionTemplate('project_docs', <<<'PHP'
        return [
            'status' => 'ok',
            'docs_directory' => 'docs/generated',
            'inspect_ui_directory' => 'docs/inspect-ui',
            'next_command' => 'foundry inspect graph --json',
            'message' => 'Refresh docs after edits with generate docs and generate inspect-ui.',
        ];
PHP);
    }

    private function feedbackActionTemplate(): string
    {
        return $this->actionTemplate('submit_feedback', <<<'PHP'
        $name = (string) ($input['name'] ?? 'friend');

        return [
            'status' => 'accepted',
            'received_name' => $name,
            'message' => 'This route exists to demonstrate validation and CSRF-aware pipeline configuration.',
        ];
PHP);
    }

    private function dashboardActionTemplate(): string
    {
        return $this->actionTemplate('dashboard', <<<'PHP'
        return [
            'status' => 'ok',
            'user_id' => (string) ($auth->userId() ?? 'unknown'),
            'message' => 'Dashboard access is protected by the development bearer header placeholder.',
        ];
PHP);
    }

    private function currentUserActionTemplate(): string
    {
        return $this->actionTemplate('current_user', <<<'PHP'
        return [
            'status' => 'ok',
            'user_id' => (string) ($auth->userId() ?? 'unknown'),
            'message' => 'Use the x-user-id header in local development to inspect protected feature behavior quickly.',
        ];
PHP);
    }

    private function apiOverviewActionTemplate(): string
    {
        return $this->actionTemplate('api_overview', <<<'PHP'
        return [
            'status' => 'ok',
            'starter' => 'api-first',
            'auth_header' => 'x-user-id',
            'message' => 'Use the x-user-id header to exercise protected API starter endpoints during local development.',
        ];
PHP);
    }

    private function apiEchoActionTemplate(): string
    {
        return $this->actionTemplate('api_echo', <<<'PHP'
        $message = (string) ($input['message'] ?? '');

        return [
            'status' => 'ok',
            'echoed_message' => $message,
            'message' => 'This route demonstrates API request validation and pipeline inspection.',
        ];
PHP);
    }

    private function apiMeActionTemplate(): string
    {
        return $this->actionTemplate('api_me', <<<'PHP'
        return [
            'status' => 'ok',
            'user_id' => (string) ($auth->userId() ?? 'unknown'),
            'auth_strategy' => 'bearer',
            'message' => 'Protected API starter route resolved through the development bearer header placeholder.',
        ];
PHP);
    }

    private function actionTemplate(string $feature, string $body): string
    {
        $namespace = 'App\\Features\\' . str_replace(' ', '', ucwords(str_replace('_', ' ', $feature)));

        return str_replace(
            ['{{NAMESPACE}}', '{{BODY}}'],
            [$namespace, $body],
            <<<'PHP'
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
{{BODY}}
    }
}
PHP
        );
    }

    /**
     * @param array<int,array<string,mixed>> $featureSpecs
     */
    private function routeSummaryMarkdown(array $featureSpecs): string
    {
        $lines = [];

        foreach ($featureSpecs as $spec) {
            $definition = (array) ($spec['definition'] ?? []);
            $route = is_array($definition['route'] ?? null) ? $definition['route'] : [];
            $lines[] = '- `'
                . strtoupper((string) ($route['method'] ?? 'GET'))
                . ' '
                . (string) ($route['path'] ?? '/')
                . '` '
                . (string) ($definition['description'] ?? '');
        }

        return implode("\n", $lines);
    }

    /**
     * @return array<int,string>
     */
    private function nextSteps(string $targetPath): array
    {
        $steps = [];
        if (rtrim($targetPath, '/') !== rtrim(getcwd() ?: '.', '/')) {
            $steps[] = 'cd ' . $targetPath;
        }

        return array_merge($steps, [
            'composer install',
            'foundry compile graph --json',
            'foundry inspect graph --json',
            'foundry inspect pipeline --json',
            'foundry doctor --json',
            'foundry generate docs --format=markdown --json',
            'foundry generate inspect-ui --json',
            'foundry verify graph --json',
            'foundry verify pipeline --json',
            'foundry verify contracts --json',
            'php vendor/bin/phpunit -c phpunit.xml.dist',
            'php -S 127.0.0.1:8000 public/index.php',
        ]);
    }

    /**
     * @param array<string,mixed> $payload
     */
    private function renderHumanMessage(array $payload): string
    {
        $lines = [
            'Foundry project scaffolded.',
            '',
            'Root: ' . (string) ($payload['project_root'] ?? ''),
            'Starter: ' . (string) ($payload['starter_label'] ?? ''),
            '',
            'Next steps:',
        ];

        foreach ((array) ($payload['next_steps'] ?? []) as $step) {
            $lines[] = '- ' . (string) $step;
        }

        return implode(PHP_EOL, $lines);
    }

    private function starterSummary(string $starterMode): string
    {
        return match ($starterMode) {
            'minimal' => 'It includes a small public route set plus generated docs and inspect surfaces so the architecture is visible immediately.',
            'standard' => 'It includes a public landing/docs flow, a validated mutation route, and protected dashboard/profile examples wired through a development auth placeholder.',
            'api-first' => 'It includes public and protected API routes, generated graph docs, and inspect surfaces aimed at API-centric application development.',
            default => 'It includes a Foundry starter structure.',
        };
    }

    private function starterAuthHint(string $starterMode): string
    {
        return match ($starterMode) {
            'standard', 'api-first' => 'Protected starter routes use the `x-user-id` header as a development auth placeholder.',
            default => 'All starter routes are public by default; add auth requirements as protected features are introduced.',
        };
    }

    private function starterLabel(string $starterMode): string
    {
        return match ($starterMode) {
            'api-first' => 'API-first',
            'minimal' => 'Minimal',
            default => 'Standard',
        };
    }

    private function parseStarterMode(array $args): string
    {
        $starter = strtolower(trim((string) ($this->parseOption($args, '--starter') ?? 'standard')));

        return match ($starter) {
            'minimal' => 'minimal',
            'standard' => 'standard',
            'api', 'api-first' => 'api-first',
            default => throw new FoundryError(
                'CLI_INIT_APP_STARTER_INVALID',
                'validation',
                ['starter' => $starter],
                'Starter must be minimal, standard, or api-first.',
            ),
        };
    }

    private function usage(bool $usingNewAlias): string
    {
        return $usingNewAlias
            ? 'foundry new [path] [--starter=minimal|standard|api-first] [--name=vendor/app] [--version=^0.1] [--force]'
            : 'foundry init app <path> [--starter=minimal|standard|api-first] [--name=vendor/app] [--version=^0.1] [--force]';
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
        if ($nonDotItems !== [] && !$force && !$this->canBootstrapIntoExistingDirectory($nonDotItems)) {
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
        $path = trim($path);
        if ($path === '' || $path === '.') {
            return rtrim($cwd, '/');
        }

        if (str_starts_with($path, './')) {
            $path = substr($path, 2);
        }

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

    /**
     * @param array<int,string> $items
     */
    private function canBootstrapIntoExistingDirectory(array $items): bool
    {
        $allowed = ['composer.json', 'composer.lock', 'vendor', 'foundry', 'foundry.bat'];

        foreach ($items as $item) {
            if (str_starts_with($item, '.')) {
                continue;
            }

            if (!in_array($item, $allowed, true)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return array<string,mixed>|null
     */
    private function loadComposerConfig(string $targetPath): ?array
    {
        $path = rtrim($targetPath, '/') . '/composer.json';
        if (!is_file($path)) {
            return null;
        }

        return Json::decodeAssoc((string) file_get_contents($path));
    }

    /**
     * @param array<string,mixed>|null $composer
     */
    private function composerName(?array $composer): ?string
    {
        $name = trim((string) ($composer['name'] ?? ''));

        return $name !== '' ? $name : null;
    }

    /**
     * @param array<string,mixed>|null $composer
     */
    private function composerFrameworkVersion(?array $composer): ?string
    {
        $require = is_array($composer['require'] ?? null) ? $composer['require'] : [];

        $version = trim((string) ($require['lofye/foundry-framework'] ?? ''));
        if ($version !== '') {
            return $version;
        }

        $legacyVersion = trim((string) ($require['lofye/foundry'] ?? ''));

        return $legacyVersion !== '' ? $legacyVersion : null;
    }

    /**
     * @param array<string,mixed> $scaffold
     * @return array<string,mixed>
     */
    private function mergedComposerConfig(Paths $paths, array $scaffold): array
    {
        $existing = $this->loadComposerConfig($paths->root());
        if ($existing === null) {
            return $scaffold;
        }

        $merged = $existing;
        $merged['name'] = trim((string) ($scaffold['name'] ?? '')) !== ''
            ? (string) $scaffold['name']
            : (string) ($existing['name'] ?? '');
        $merged['description'] = (string) ($scaffold['description'] ?? ($existing['description'] ?? ''));
        $merged['type'] = 'project';

        $require = is_array($existing['require'] ?? null) ? $existing['require'] : [];
        unset($require['lofye/foundry']);
        foreach ((array) ($scaffold['require'] ?? []) as $package => $constraint) {
            $require[(string) $package] = $constraint;
        }
        ksort($require);
        $merged['require'] = $require;

        $requireDev = is_array($existing['require-dev'] ?? null) ? $existing['require-dev'] : [];
        foreach ((array) ($scaffold['require-dev'] ?? []) as $package => $constraint) {
            $requireDev[(string) $package] = $constraint;
        }
        ksort($requireDev);
        $merged['require-dev'] = $requireDev;

        $autoload = is_array($existing['autoload'] ?? null) ? $existing['autoload'] : [];
        $psr4 = is_array($autoload['psr-4'] ?? null) ? $autoload['psr-4'] : [];
        $psr4['App\\'] = 'app/';
        ksort($psr4);
        $autoload['psr-4'] = $psr4;
        $merged['autoload'] = $autoload;

        $scripts = is_array($existing['scripts'] ?? null) ? $existing['scripts'] : [];
        foreach ((array) ($scaffold['scripts'] ?? []) as $name => $command) {
            $scripts[(string) $name] = $command;
        }
        ksort($scripts);
        $merged['scripts'] = $scripts;

        $merged['minimum-stability'] = (string) ($scaffold['minimum-stability'] ?? 'dev');
        $merged['prefer-stable'] = (bool) ($scaffold['prefer-stable'] ?? true);

        $lockPath = $paths->join('composer.lock');
        if (is_file($lockPath)) {
            @unlink($lockPath);
        }

        return $merged;
    }

    private function displayName(string $targetPath): string
    {
        $base = basename(rtrim($targetPath, '/'));
        $label = trim((string) preg_replace('/[-_]+/', ' ', $base));
        $label = ucwords($label);

        return $label !== '' ? $label : 'Foundry App';
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
