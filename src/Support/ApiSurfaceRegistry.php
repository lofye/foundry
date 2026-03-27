<?php
declare(strict_types=1);

namespace Foundry\Support;

final class ApiSurfaceRegistry
{
    /**
     * @return array<string,mixed>
     */
    public function describe(): array
    {
        return [
            'schema_version' => 1,
            'policy' => $this->policy(),
            'categories' => $this->categories(),
            'php_namespace_rules' => $this->phpNamespaceRules(),
            'php_symbol_overrides' => $this->phpSymbolOverrides(),
            'cli_commands' => $this->cliCommands(),
            'configuration_formats' => $this->configurationFormats(),
            'manifest_schemas' => $this->manifestSchemas(),
            'extension_hooks' => $this->extensionHooks(),
            'generated_metadata_formats' => $this->generatedMetadataFormats(),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public function policy(): array
    {
        return [
            'classification_strategy' => 'Explicit registry with namespace-prefix rules and exact symbol overrides. Anything not listed as public, extension, or experimental defaults to internal.',
            'naming_rules' => [
                'Stable PHP APIs must match a listed public namespace rule or an exact symbol override.',
                'Compiler implementation namespaces remain internal unless a symbol is explicitly listed as an extension hook.',
                'Experimental surfaces must be labeled experimental in CLI help and generated reference docs.',
                'Generated metadata and build artifacts are internal unless a path pattern is explicitly listed otherwise.',
            ],
            'pre_1_0' => [
                'stable' => 'Listed stable public and extension APIs are treated as compatibility promises immediately. Any unavoidable break requires a documented deprecation and upgrade note.',
                'experimental' => 'Experimental APIs may change in minor releases, but must remain clearly marked in docs and CLI output.',
                'internal' => 'Internal APIs may change without notice.',
            ],
            'post_1_0' => [
                'stable' => 'Stable public APIs follow semantic versioning: breaking changes only in major releases.',
                'experimental' => 'Experimental APIs remain opt-in and may still change in minor releases while marked experimental.',
                'internal' => 'Internal APIs remain outside semver guarantees.',
            ],
            'cli_stability' => [
                'stable' => 'Stable commands have semver-governed names, options, and JSON output shapes.',
                'experimental' => 'Experimental commands are supported, but may change in minor releases while clearly labeled.',
                'internal' => 'Internal commands are for framework and developer workflows only and may change at any time.',
            ],
        ];
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function categories(): array
    {
        return [
            [
                'classification' => 'public_api',
                'label' => 'Public API',
                'summary' => 'Application-facing PHP APIs, manifests, and CLI contracts that are safe to depend on.',
            ],
            [
                'classification' => 'extension_api',
                'label' => 'Extension API',
                'summary' => 'Supported extension contracts that carry compatibility expectations and explicit version constraints.',
            ],
            [
                'classification' => 'experimental_api',
                'label' => 'Experimental API',
                'summary' => 'Visible surfaces that are intentionally available before 1.0, but may still change in minor releases.',
            ],
            [
                'classification' => 'internal_api',
                'label' => 'Internal API',
                'summary' => 'Implementation detail. Do not depend on it from apps or extensions.',
            ],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public function classifyPhpSymbol(string $symbol): array
    {
        $symbol = ltrim(trim($symbol), '\\');

        foreach ($this->phpSymbolOverrides() as $entry) {
            if ((string) ($entry['identifier'] ?? '') === $symbol) {
                return $this->withMatch($entry, 'exact_symbol');
            }
        }

        $longestMatch = null;
        foreach ($this->phpNamespaceRules() as $entry) {
            $prefix = (string) ($entry['identifier'] ?? '');
            if ($prefix === '' || !str_starts_with($symbol, $prefix)) {
                continue;
            }

            if ($longestMatch === null || strlen($prefix) > strlen((string) ($longestMatch['identifier'] ?? ''))) {
                $longestMatch = $entry;
            }
        }

        if (is_array($longestMatch)) {
            return $this->withMatch($longestMatch, 'namespace_rule');
        }

        return $this->withMatch(
            $this->surfaceEntry(
                kind: 'php_symbol',
                identifier: $symbol,
                classification: 'internal_api',
                stability: 'internal',
                summary: 'Unlisted PHP symbols default to internal API.',
            ),
            'default_internal',
        );
    }

    public function resolveCliSignature(array $args): ?string
    {
        $args = array_values(array_filter(
            array_map('strval', $args),
            static fn (string $arg): bool => $arg !== '--json',
        ));

        $first = (string) ($args[0] ?? '');
        $second = (string) ($args[1] ?? '');
        $generateTargets = [
            'feature',
            'starter',
            'resource',
            'admin-resource',
            'uploads',
            'notification',
            'api-resource',
            'docs',
            'indexes',
            'tests',
            'migration',
            'context',
            'billing',
            'workflow',
            'orchestration',
            'search-index',
            'stream',
            'locale',
            'roles',
            'policy',
            'inspect-ui',
        ];

        if ($first === '') {
            return null;
        }

        return match ($first) {
            'help', 'new', 'serve', 'queue:work', 'queue:inspect', 'schedule:run', 'trace:tail', 'affected-files', 'impacted-features', 'upgrade-check', 'explain', 'diff', 'trace' => $first,
            'compile', 'graph', 'export', 'preview', 'init', 'migrate', 'codemod', 'cache' => match ($first) {
                'compile' => $second === 'graph' ? 'compile graph' : null,
                'graph' => match ($second) {
                    'inspect' => 'graph inspect',
                    'visualize' => 'graph visualize',
                    default => null,
                },
                'export' => match ($second) {
                    'graph' => 'export graph',
                    'openapi' => 'export openapi',
                    default => null,
                },
                'preview' => $second === 'notification' ? 'preview notification' : null,
                'init' => $second === 'app' ? 'init app' : null,
                'migrate' => $second === 'definitions' ? 'migrate definitions' : null,
                'codemod' => $second === 'run' ? 'codemod run' : null,
                'cache' => match ($second) {
                    'inspect' => 'cache inspect',
                    'clear' => 'cache clear',
                    default => null,
                },
                default => null,
            },
            'doctor', 'prompt' => $first,
            'pro' => match ($second) {
                '' => 'pro',
                'status' => 'pro status',
                'enable' => 'pro enable',
                default => 'pro',
            },
            'inspect', 'verify' => $second !== '' ? $first . ' ' . $second : null,
            'generate' => $second === '' || str_starts_with($second, '--')
                ? null
                : (in_array($second, $generateTargets, true) ? 'generate ' . $second : 'generate <prompt>'),
            default => null,
        };
    }

    /**
     * @param string|array<int,string> $command
     * @return array<string,mixed>|null
     */
    public function classifyCliCommand(string|array $command): ?array
    {
        $signature = is_array($command)
            ? $this->resolveCliSignature($command)
            : trim($command);

        if ($signature === null || $signature === '') {
            return null;
        }

        foreach ($this->cliCommands() as $entry) {
            if ((string) ($entry['signature'] ?? '') === $signature) {
                return $entry;
            }
        }

        return null;
    }

    /**
     * @return array<string,mixed>|null
     */
    public function classifyConfigurationArtifact(string $path): ?array
    {
        $normalized = $this->normalizePath($path);

        foreach (array_merge($this->configurationFormats(), $this->manifestSchemas()) as $entry) {
            if ($this->matchesPattern($normalized, (string) ($entry['identifier'] ?? ''))) {
                return $this->withMatch($entry, 'path_pattern');
            }
        }

        return null;
    }

    /**
     * @return array<string,mixed>|null
     */
    public function classifyGeneratedMetadata(string $path): ?array
    {
        $normalized = $this->normalizePath($path);

        foreach ($this->generatedMetadataFormats() as $entry) {
            if ($this->matchesPattern($normalized, (string) ($entry['identifier'] ?? ''))) {
                return $this->withMatch($entry, 'path_pattern');
            }
        }

        return null;
    }

    /**
     * @return array<string,mixed>
     */
    public function cliHelpIndex(): array
    {
        $groups = [
            'stable' => [],
            'experimental' => [],
            'internal' => [],
        ];

        foreach ($this->cliCommands() as $entry) {
            $stability = (string) ($entry['stability'] ?? 'internal');
            $groups[$stability] ??= [];
            $groups[$stability][] = $entry;
        }

        foreach ($groups as &$entries) {
            usort(
                $entries,
                static fn (array $a, array $b): int => strcmp((string) ($a['signature'] ?? ''), (string) ($b['signature'] ?? '')),
            );
        }
        unset($entries);

        return [
            'summary' => [
                'stable' => count($groups['stable']),
                'experimental' => count($groups['experimental']),
                'internal' => count($groups['internal']),
                'total' => count($this->cliCommands()),
            ],
            'policy' => $this->policy()['cli_stability'],
            'commands' => $groups,
        ];
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function phpNamespaceRules(): array
    {
        return [
            $this->surfaceEntry('php_namespace', 'Foundry\\Feature\\', 'public_api', 'stable', 'Feature contracts and registries intended for application code.'),
            $this->surfaceEntry('php_namespace', 'Foundry\\Events\\', 'public_api', 'stable', 'Event definitions and dispatcher contracts intended for app and runtime code.'),
            $this->surfaceEntry('php_namespace', 'Foundry\\DB\\', 'public_api', 'stable', 'Database execution and query contracts intended for runtime code.'),
            $this->surfaceEntry('php_namespace', 'Foundry\\Queue\\', 'public_api', 'stable', 'Queue dispatch, registry, and driver contracts intended for runtime code.'),
            $this->surfaceEntry('php_namespace', 'Foundry\\Storage\\', 'public_api', 'stable', 'Storage driver contracts and file descriptors intended for runtime code.'),
            $this->surfaceEntry('php_namespace', 'Foundry\\Workflow\\', 'public_api', 'stable', 'Workflow runtime engine surface intended for application code.'),
            $this->surfaceEntry('php_namespace', 'Foundry\\Notifications\\', 'public_api', 'stable', 'Notification runtime contracts intended for application code.'),
            $this->surfaceEntry('php_namespace', 'Foundry\\Localization\\', 'public_api', 'stable', 'Locale catalog runtime contracts intended for application code.'),
            $this->surfaceEntry('php_namespace', 'Foundry\\Billing\\', 'public_api', 'stable', 'Billing registry contracts intended for runtime code.'),
            $this->surfaceEntry('php_namespace', 'Foundry\\AI\\', 'experimental_api', 'experimental', 'AI provider contracts remain experimental until the AI integration surface is frozen.'),
            $this->surfaceEntry('php_namespace', 'Foundry\\Compiler\\Passes\\', 'internal_api', 'internal', 'Compiler passes are implementation detail.'),
            $this->surfaceEntry('php_namespace', 'Foundry\\Compiler\\IR\\', 'internal_api', 'internal', 'Graph node implementations are internal compiler detail.'),
            $this->surfaceEntry('php_namespace', 'Foundry\\Compiler\\Projection\\', 'internal_api', 'internal', 'Projection implementations are internal unless explicitly listed as extension hooks.'),
            $this->surfaceEntry('php_namespace', 'Foundry\\Compiler\\Analysis\\', 'internal_api', 'internal', 'Compiler analyzers are internal unless explicitly listed as extension hooks.'),
            $this->surfaceEntry('php_namespace', 'Foundry\\Compiler\\', 'internal_api', 'internal', 'Compiler internals remain changeable unless a symbol is explicitly listed as extension API.'),
            $this->surfaceEntry('php_namespace', 'Foundry\\CLI\\', 'internal_api', 'internal', 'CLI implementation classes are internal; the stable contract is the command surface, not the PHP classes.'),
            $this->surfaceEntry('php_namespace', 'Foundry\\Generation\\', 'internal_api', 'internal', 'Generators are implementation detail behind CLI commands.'),
            $this->surfaceEntry('php_namespace', 'Foundry\\Documentation\\', 'internal_api', 'internal', 'Documentation generators are internal implementation detail.'),
            $this->surfaceEntry('php_namespace', 'Foundry\\Verification\\', 'internal_api', 'internal', 'Verifier implementation classes are internal.'),
            $this->surfaceEntry('php_namespace', 'Foundry\\Support\\', 'internal_api', 'internal', 'Support helpers are internal unless explicitly documented otherwise.'),
            $this->surfaceEntry('php_namespace', 'Foundry\\Pipeline\\', 'internal_api', 'internal', 'Pipeline implementation classes are internal unless explicitly listed as extension hooks.'),
        ];
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function phpSymbolOverrides(): array
    {
        return $this->extensionHooks();
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function cliCommands(): array
    {
        $commands = [
            $this->cliCommandEntry('help', 'help [<command> [<subcommand>]]', 'stable', 'Show the classified CLI reference and per-command stability details.'),
            $this->cliCommandEntry('compile graph', 'compile graph [--feature=<feature>] [--changed-only] [--no-cache]', 'stable', 'Compile source-of-truth files into the canonical application graph.'),
            $this->cliCommandEntry('cache inspect', 'cache inspect', 'stable', 'Inspect deterministic compile cache state, keys, and invalidation reasons.'),
            $this->cliCommandEntry('cache clear', 'cache clear', 'stable', 'Clear deterministic compile cache artifacts and generated projections.'),
            $this->cliCommandEntry('doctor', 'doctor [--feature=<feature>] [--strict] [--deep]', 'experimental', 'Diagnose environment, install, build, and architecture issues from current Foundry state. Deep diagnostics require Foundry Pro.'),
            $this->cliCommandEntry('upgrade-check', 'upgrade-check [--target=<version>]', 'stable', 'Assess whether the current app is ready for a target framework upgrade.'),
            $this->cliCommandEntry('graph inspect', 'graph inspect [--view=<view>|--events|--routes|--caches|--pipeline|--workflows|--extensions] [--feature=<feature>] [--extension=<extension>] [--pipeline-stage=<stage>] [--command=<target>] [--event=<name>] [--workflow=<name>] [--format=mermaid|dot|svg|json]', 'stable', 'Inspect graph summaries and filtered architecture slices through the stable graph surface.'),
            $this->cliCommandEntry('graph visualize', 'graph visualize [--view=<view>|--events|--routes|--caches|--pipeline|--workflows|--extensions] [--feature=<feature>] [--extension=<extension>] [--pipeline-stage=<stage>] [--command=<target>] [--event=<name>] [--workflow=<name>] [--format=mermaid|dot|svg|json]', 'stable', 'Render graph slices through the stable graph inspection surface.'),
            $this->cliCommandEntry('prompt', 'prompt <instruction...> [--feature-context] [--dry-run]', 'experimental', 'Build structured AI-edit prompts from current graph state.'),
            $this->cliCommandEntry('pro', 'pro [status]', 'experimental', 'Inspect local Foundry Pro licensing status and available Pro commands.', 'pro'),
            $this->cliCommandEntry('pro enable', 'pro enable <license-key>', 'experimental', 'Validate and store a local Foundry Pro license key without any required network call.', 'pro'),
            $this->cliCommandEntry('pro status', 'pro status', 'experimental', 'Show the local Foundry Pro license status.', 'pro'),
            $this->cliCommandEntry('explain', 'explain <target> [--type=<kind>] [--markdown] [--deep] [--neighbors|--no-neighbors] [--no-diagnostics] [--no-flow]', 'experimental', 'Explain a framework or application subject from the compiled graph, projections, diagnostics, and docs metadata.', 'pro'),
            $this->cliCommandEntry('diff', 'diff', 'experimental', 'Compare the current graph against the last compiled baseline.', 'pro'),
            $this->cliCommandEntry('trace', 'trace [<target>]', 'experimental', 'Analyze local trace output for a feature, route, or free-form filter.', 'pro'),
            $this->cliCommandEntry('generate <prompt>', 'generate <prompt...> [--feature-context] [--dry-run] [--deterministic] [--provider=<name>] [--model=<name>] [--force]', 'experimental', 'Plan or generate graph-aware feature scaffolding from the current graph using deterministic or configured AI provider mode.', 'pro'),
            $this->cliCommandEntry('serve', 'serve', 'internal', 'Emit the lightweight local PHP server hint used in development.'),
            $this->cliCommandEntry('queue:work', 'queue:work [<queue>]', 'internal', 'Run the local queue worker loop.'),
            $this->cliCommandEntry('queue:inspect', 'queue:inspect [<queue>]', 'internal', 'Inspect queued jobs for local development.'),
            $this->cliCommandEntry('schedule:run', 'schedule:run', 'internal', 'Run scheduled tasks in the local development loop.'),
            $this->cliCommandEntry('trace:tail', 'trace:tail', 'internal', 'Tail local trace output for development debugging.'),
            $this->cliCommandEntry('affected-files', 'affected-files <feature>', 'internal', 'List source-of-truth files associated with a feature.'),
            $this->cliCommandEntry('impacted-features', 'impacted-features <permission|event:<name>|cache:<key>>', 'internal', 'Resolve impacted features from a runtime contract identifier.'),
            $this->cliCommandEntry('new', 'new <target> [--starter=<minimal|standard|api-first>] [--name=<package>] [--version=<constraint>] [--force]', 'stable', 'Scaffold a new Foundry application.'),
            $this->cliCommandEntry('init app', 'init app <target> [--starter=<minimal|standard|api-first>] [--name=<package>] [--version=<constraint>] [--force]', 'stable', 'Scaffold a new Foundry application (legacy alias).'),
            $this->cliCommandEntry('migrate definitions', 'migrate definitions [--path=<path>] [--dry-run|--write]', 'experimental', 'Apply framework-provided definition migrations.'),
            $this->cliCommandEntry('codemod run', 'codemod run <id> [--path=<path>] [--dry-run|--write]', 'experimental', 'Run explicit source codemods contributed by the framework or extensions.'),
            $this->cliCommandEntry('export graph', 'export graph [--view=<view>|--events|--routes|--caches|--pipeline|--workflows|--extensions] [--feature=<feature>] [--extension=<extension>] [--pipeline-stage=<stage>] [--command=<target>] [--event=<name>] [--workflow=<name>] [--format=json|dot|mermaid|svg] [--output=<path>]', 'stable', 'Export graph slices to docs- and tooling-friendly files.'),
            $this->cliCommandEntry('export openapi', 'export openapi [--format=json|yaml]', 'stable', 'Export OpenAPI documents derived from the canonical graph.'),
            $this->cliCommandEntry('preview notification', 'preview notification <name>', 'stable', 'Render notification output from current graph state.'),
        ];

        foreach ([
            'graph' => ['stable', 'Inspect high-level graph metadata, summaries, and filtered architecture slices.'],
            'build' => ['internal', 'Inspect compiler build artifacts and manifests used for framework debugging.'],
            'node' => ['stable', 'Inspect a single graph node and its related diagnostics.'],
            'dependencies' => ['stable', 'Inspect feature or graph-node dependencies from compiled graph state.'],
            'dependents' => ['stable', 'Inspect graph nodes that depend on the given node.'],
            'pipeline' => ['stable', 'Inspect the resolved execution pipeline.'],
            'execution-plan' => ['stable', 'Inspect the resolved execution plan for a feature or route.'],
            'guards' => ['stable', 'Inspect resolved execution guards.'],
            'interceptors' => ['stable', 'Inspect resolved pipeline interceptors.'],
            'impact' => ['stable', 'Inspect impacted nodes and tests from a node or source file.'],
            'affected-tests' => ['stable', 'Inspect tests affected by a graph node.'],
            'affected-features' => ['stable', 'Inspect features affected by a graph node.'],
            'extensions' => ['experimental', 'Inspect registered compiler extensions.'],
            'extension' => ['experimental', 'Inspect a single compiler extension descriptor.'],
            'packs' => ['experimental', 'Inspect registered extension packs.'],
            'pack' => ['experimental', 'Inspect a single extension pack definition.'],
            'compatibility' => ['experimental', 'Inspect extension compatibility against the current framework and graph versions.'],
            'migrations' => ['experimental', 'Inspect registered definition formats, migrations, and codemods.'],
            'definition-format' => ['experimental', 'Inspect a registered definition format contract.'],
            'api-surface' => ['stable', 'Inspect the classified public, extension, experimental, and internal API registry.'],
        ] as $target => [$stability, $summary]) {
            $usage = match ($target) {
                'graph' => 'inspect graph [--view=<view>|--events|--routes|--caches|--pipeline|--workflows|--extensions] [--feature=<feature>] [--extension=<extension>] [--pipeline-stage=<stage>] [--command=<target>] [--event=<name>] [--workflow=<name>] [--format=mermaid|dot|svg|json]',
                'build', 'pipeline', 'extensions', 'packs', 'compatibility', 'migrations' => 'inspect ' . $target,
                'node', 'dependents', 'affected-tests', 'affected-features' => 'inspect ' . $target . ' <node-id>',
                'dependencies' => 'inspect dependencies <feature|node-id>',
                'execution-plan' => 'inspect execution-plan <feature>|--feature=<feature>|--route=<METHOD PATH>',
                'guards' => 'inspect guards [<feature>]',
                'interceptors' => 'inspect interceptors [--stage=<stage>]',
                'impact' => 'inspect impact <node-id>|--file=<path>',
                'extension', 'pack', 'definition-format' => 'inspect ' . $target . ' <name>',
                'api-surface' => 'inspect api-surface [--php=<symbol>] [--command=<signature>] [--path=<artifact>]',
                default => 'inspect ' . $target,
            };

            $commands[] = $this->cliCommandEntry('inspect ' . $target, $usage, (string) $stability, (string) $summary);
        }

        foreach ([
            'feature' => ['stable', 'inspect feature <feature>', 'Inspect compiled feature details.'],
            'auth' => ['stable', 'inspect auth <feature>', 'Inspect feature auth configuration.'],
            'cache' => ['stable', 'inspect cache <feature>', 'Inspect feature cache declarations.'],
            'events' => ['stable', 'inspect events <feature>', 'Inspect feature event declarations.'],
            'jobs' => ['stable', 'inspect jobs <feature>', 'Inspect feature job declarations.'],
            'context' => ['stable', 'inspect context <feature>', 'Inspect feature context manifest data.'],
            'notification' => ['stable', 'inspect notification <name>', 'Inspect a notification contract.'],
            'api' => ['stable', 'inspect api <name>', 'Inspect an API resource contract.'],
            'resource' => ['stable', 'inspect resource <name>', 'Inspect a generated resource contract.'],
            'route' => ['stable', 'inspect route <METHOD> <PATH>', 'Inspect a route signature and attached feature data.'],
            'billing' => ['stable', 'inspect billing [--provider=<name>]', 'Inspect billing providers.'],
            'workflow' => ['stable', 'inspect workflow <name>', 'Inspect a workflow definition.'],
            'orchestration' => ['stable', 'inspect orchestration <name>', 'Inspect an orchestration definition.'],
            'search' => ['stable', 'inspect search <name>', 'Inspect a search index definition.'],
            'streams' => ['stable', 'inspect streams', 'Inspect stream definitions.'],
            'locales' => ['stable', 'inspect locales', 'Inspect locale bundle definitions.'],
            'roles' => ['stable', 'inspect roles', 'Inspect role and policy definitions.'],
        ] as $target => [$stability, $usage, $summary]) {
            $commands[] = $this->cliCommandEntry('inspect ' . $target, (string) $usage, (string) $stability, (string) $summary);
        }

        foreach ([
            'feature' => ['experimental', 'generate feature <definition.yaml>', 'Generate a feature from a scaffold definition.'],
            'tests' => ['experimental', 'generate tests <feature>|--all-missing [--mode=feature|deep|resource|api|notification]', 'Generate framework-managed tests.'],
            'context' => ['experimental', 'generate context <feature>', 'Generate a feature context manifest.'],
            'starter' => ['experimental', 'generate starter <server-rendered|api> [--force]', 'Generate a starter application slice.'],
            'resource' => ['experimental', 'generate resource <name> --definition=<file> [--force]', 'Generate a resource scaffold from a definition file.'],
            'admin-resource' => ['experimental', 'generate admin-resource <name> [--force]', 'Generate an admin resource scaffold.'],
            'uploads' => ['experimental', 'generate uploads <profile> [--force]', 'Generate upload profile scaffolding.'],
            'notification' => ['experimental', 'generate notification <name> [--force]', 'Generate notification scaffolding.'],
            'api-resource' => ['experimental', 'generate api-resource <name> --definition=<file> [--force]', 'Generate an API resource from a definition file.'],
            'docs' => ['stable', 'generate docs [--format=markdown|html]', 'Generate graph-derived docs and CLI/API reference output.'],
            'indexes' => ['internal', 'generate indexes', 'Regenerate projection indexes directly for framework workflows.'],
            'migration' => ['internal', 'generate migration <definition.yaml>', 'Generate migration helper output for framework workflows.'],
            'billing' => ['experimental', 'generate billing <provider> [--force]', 'Generate billing provider scaffolding.'],
            'workflow' => ['experimental', 'generate workflow <name> --definition=<file> [--force]', 'Generate workflow scaffolding.'],
            'orchestration' => ['experimental', 'generate orchestration <name> --definition=<file> [--force]', 'Generate orchestration scaffolding.'],
            'search-index' => ['experimental', 'generate search-index <name> --definition=<file> [--force]', 'Generate search index scaffolding.'],
            'stream' => ['experimental', 'generate stream <name> [--force]', 'Generate stream scaffolding.'],
            'locale' => ['experimental', 'generate locale <name> [--force]', 'Generate locale scaffolding.'],
            'roles' => ['experimental', 'generate roles [--force]', 'Generate roles scaffolding.'],
            'policy' => ['experimental', 'generate policy <name> [--force]', 'Generate policy scaffolding.'],
            'inspect-ui' => ['experimental', 'generate inspect-ui', 'Generate the inspect UI static site.'],
        ] as $target => [$stability, $usage, $summary]) {
            $commands[] = $this->cliCommandEntry('generate ' . $target, (string) $usage, (string) $stability, (string) $summary);
        }

        foreach ([
            'graph' => ['stable', 'verify graph', 'Verify graph build integrity and generated artifacts.'],
            'pipeline' => ['stable', 'verify pipeline', 'Verify execution pipeline completeness and ordering.'],
            'extensions' => ['experimental', 'verify extensions', 'Verify extension registration and compatibility warnings.'],
            'compatibility' => ['experimental', 'verify compatibility', 'Verify extension and pack compatibility contracts.'],
            'feature' => ['stable', 'verify feature <feature>', 'Verify feature-local contract completeness.'],
            'resource' => ['stable', 'verify resource <name>', 'Verify resource contracts.'],
            'notifications' => ['stable', 'verify notifications', 'Verify notification contracts.'],
            'api' => ['stable', 'verify api', 'Verify API resource contracts.'],
            'billing' => ['stable', 'verify billing', 'Verify billing provider contracts.'],
            'workflows' => ['stable', 'verify workflows', 'Verify workflow contracts.'],
            'orchestrations' => ['stable', 'verify orchestrations', 'Verify orchestration contracts.'],
            'search' => ['stable', 'verify search', 'Verify search index contracts.'],
            'streams' => ['stable', 'verify streams', 'Verify stream contracts.'],
            'locales' => ['stable', 'verify locales', 'Verify locale bundle contracts.'],
            'policies' => ['stable', 'verify policies', 'Verify role and policy contracts.'],
            'contracts' => ['stable', 'verify contracts', 'Verify aggregate application contracts.'],
            'auth' => ['stable', 'verify auth', 'Verify auth declarations.'],
            'cache' => ['stable', 'verify cache', 'Verify cache declarations.'],
            'events' => ['stable', 'verify events', 'Verify event declarations.'],
            'jobs' => ['stable', 'verify jobs', 'Verify job declarations.'],
            'migrations' => ['stable', 'verify migrations', 'Verify migration contract state.'],
        ] as $target => [$stability, $usage, $summary]) {
            $commands[] = $this->cliCommandEntry('verify ' . $target, (string) $usage, (string) $stability, (string) $summary);
        }

        usort(
            $commands,
            static fn (array $a, array $b): int => strcmp((string) ($a['signature'] ?? ''), (string) ($b['signature'] ?? '')),
        );

        return $commands;
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function configurationFormats(): array
    {
        return [
            $this->surfaceEntry('configuration_format', 'foundry.extensions.php', 'extension_api', 'stable', 'Root-level extension registration file for framework and app packs.'),
            $this->surfaceEntry('configuration_format', 'config/foundry/extensions.php', 'extension_api', 'stable', 'App-local extension registration file layered after the project root registration file.'),
            $this->surfaceEntry('configuration_format', 'config/*.php', 'experimental_api', 'experimental', 'Application config files remain experimental until schema validation is finalized.'),
            $this->surfaceEntry('configuration_format', 'definitions/*.api-resource.yaml', 'experimental_api', 'experimental', 'Definition files used by API resource generators remain experimental.'),
            $this->surfaceEntry('configuration_format', 'definitions/*.workflow.yaml', 'experimental_api', 'experimental', 'Definition files used by workflow generators remain experimental.'),
            $this->surfaceEntry('configuration_format', 'definitions/*.orchestration.yaml', 'experimental_api', 'experimental', 'Definition files used by orchestration generators remain experimental.'),
            $this->surfaceEntry('configuration_format', 'definitions/*.search.yaml', 'experimental_api', 'experimental', 'Definition files used by search index generators remain experimental.'),
        ];
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function manifestSchemas(): array
    {
        return [
            $this->surfaceEntry('manifest_schema', 'app/features/*/feature.yaml', 'public_api', 'stable', 'Feature manifest contract safe for application code and tooling.'),
            $this->surfaceEntry('manifest_schema', 'app/features/*/input.schema.json', 'public_api', 'stable', 'Feature input schema contract safe to depend on.'),
            $this->surfaceEntry('manifest_schema', 'app/features/*/output.schema.json', 'public_api', 'stable', 'Feature output schema contract safe to depend on.'),
            $this->surfaceEntry('manifest_schema', 'app/features/*/context.manifest.json', 'public_api', 'stable', 'Feature context manifest contract safe to depend on.'),
            $this->surfaceEntry('manifest_schema', 'app/features/*/permissions.yaml', 'public_api', 'stable', 'Feature permission declaration contract safe to depend on.'),
            $this->surfaceEntry('manifest_schema', 'app/features/*/cache.yaml', 'public_api', 'stable', 'Feature cache declaration contract safe to depend on.'),
            $this->surfaceEntry('manifest_schema', 'app/features/*/events.yaml', 'public_api', 'stable', 'Feature event declaration contract safe to depend on.'),
            $this->surfaceEntry('manifest_schema', 'app/features/*/jobs.yaml', 'public_api', 'stable', 'Feature job declaration contract safe to depend on.'),
            $this->surfaceEntry('manifest_schema', 'app/features/*/prompts.md', 'experimental_api', 'experimental', 'Feature-local prompt notes remain experimental authoring aid.'),
        ];
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function extensionHooks(): array
    {
        return [
            $this->surfaceEntry('extension_hook', 'Foundry\\Compiler\\Extensions\\CompilerExtension', 'extension_api', 'stable', 'Primary extension registration contract.'),
            $this->surfaceEntry('extension_hook', 'Foundry\\Compiler\\Extensions\\AbstractCompilerExtension', 'extension_api', 'stable', 'Convenience base class for compiler extensions.'),
            $this->surfaceEntry('extension_hook', 'Foundry\\Compiler\\Extensions\\ExtensionDescriptor', 'extension_api', 'stable', 'Extension metadata contract exposed to compatibility tooling.'),
            $this->surfaceEntry('extension_hook', 'Foundry\\Compiler\\Extensions\\PackDefinition', 'extension_api', 'stable', 'Pack metadata contract exposed to inspect and verify surfaces.'),
            $this->surfaceEntry('extension_hook', 'Foundry\\Compiler\\Extensions\\VersionConstraint', 'extension_api', 'stable', 'Version constraint helper used by extension compatibility checks.'),
            $this->surfaceEntry('extension_hook', 'Foundry\\Compiler\\CompilerPass', 'extension_api', 'stable', 'Compiler pass contract used by registered extensions.'),
            $this->surfaceEntry('extension_hook', 'Foundry\\Compiler\\CompilationState', 'extension_api', 'stable', 'Mutable compiler state exposed to extension passes.'),
            $this->surfaceEntry('extension_hook', 'Foundry\\Compiler\\ApplicationGraph', 'extension_api', 'stable', 'Canonical graph contract exposed to analyzers and projection emitters.'),
            $this->surfaceEntry('extension_hook', 'Foundry\\Compiler\\GraphEdge', 'extension_api', 'stable', 'Graph edge contract exposed when extensions inspect graph relationships.'),
            $this->surfaceEntry('extension_hook', 'Foundry\\Compiler\\Diagnostics\\DiagnosticBag', 'extension_api', 'stable', 'Diagnostics sink exposed to extension passes and analyzers.'),
            $this->surfaceEntry('extension_hook', 'Foundry\\Compiler\\Diagnostics\\Diagnostic', 'extension_api', 'stable', 'Structured diagnostic record exposed through extension-facing diagnostics flows.'),
            $this->surfaceEntry('extension_hook', 'Foundry\\Doctor\\DoctorCheck', 'extension_api', 'stable', 'Doctor check contract for environment and architecture diagnostics.'),
            $this->surfaceEntry('extension_hook', 'Foundry\\Doctor\\DoctorContext', 'extension_api', 'stable', 'Context exposed to custom doctor checks registered by applications and extensions.'),
            $this->surfaceEntry('extension_hook', 'Foundry\\Compiler\\Projection\\ProjectionEmitter', 'extension_api', 'stable', 'Projection emitter contract for generated metadata outputs.'),
            $this->surfaceEntry('extension_hook', 'Foundry\\Compiler\\Migration\\MigrationRule', 'extension_api', 'stable', 'Definition migration rule contract.'),
            $this->surfaceEntry('extension_hook', 'Foundry\\Compiler\\Migration\\DefinitionFormat', 'extension_api', 'stable', 'Definition format metadata contract.'),
            $this->surfaceEntry('extension_hook', 'Foundry\\Compiler\\Codemod\\Codemod', 'extension_api', 'stable', 'Codemod contract used by explicit source rewrite operations.'),
            $this->surfaceEntry('extension_hook', 'Foundry\\Compiler\\Analysis\\GraphAnalyzer', 'extension_api', 'stable', 'Analyzer contract registered through compiler extensions.'),
            $this->surfaceEntry('extension_hook', 'Foundry\\Explain\\Contributors\\ExplainContributorInterface', 'extension_api', 'stable', 'Explain contribution contract for deterministic architecture explanation sections.'),
            $this->surfaceEntry('extension_hook', 'Foundry\\Explain\\Contributors\\ExplainContribution', 'extension_api', 'stable', 'Structured contribution payload merged into explain plans before rendering.'),
            $this->surfaceEntry('extension_hook', 'Foundry\\Pipeline\\PipelineStageDefinition', 'extension_api', 'stable', 'Pipeline stage definition contract for extension-registered stages.'),
            $this->surfaceEntry('extension_hook', 'Foundry\\Pipeline\\StageInterceptor', 'extension_api', 'stable', 'Pipeline interceptor contract for extension-registered interceptors.'),
        ];
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function generatedMetadataFormats(): array
    {
        return [
            $this->surfaceEntry('generated_metadata', 'app/generated/routes.php', 'internal_api', 'internal', 'Legacy runtime route projection.'),
            $this->surfaceEntry('generated_metadata', 'app/generated/feature_index.php', 'internal_api', 'internal', 'Legacy runtime feature projection.'),
            $this->surfaceEntry('generated_metadata', 'app/generated/schema_index.php', 'internal_api', 'internal', 'Legacy runtime schema projection.'),
            $this->surfaceEntry('generated_metadata', 'app/generated/permission_index.php', 'internal_api', 'internal', 'Legacy runtime permission projection.'),
            $this->surfaceEntry('generated_metadata', 'app/generated/event_index.php', 'internal_api', 'internal', 'Legacy runtime event projection.'),
            $this->surfaceEntry('generated_metadata', 'app/generated/job_index.php', 'internal_api', 'internal', 'Legacy runtime job projection.'),
            $this->surfaceEntry('generated_metadata', 'app/generated/cache_index.php', 'internal_api', 'internal', 'Legacy runtime cache projection.'),
            $this->surfaceEntry('generated_metadata', 'app/generated/scheduler_index.php', 'internal_api', 'internal', 'Legacy runtime scheduler projection.'),
            $this->surfaceEntry('generated_metadata', 'app/generated/webhook_index.php', 'internal_api', 'internal', 'Legacy runtime webhook projection.'),
            $this->surfaceEntry('generated_metadata', 'app/.foundry/build/graph/app_graph.json', 'internal_api', 'internal', 'Canonical graph JSON artifact.'),
            $this->surfaceEntry('generated_metadata', 'app/.foundry/build/graph/app_graph.php', 'internal_api', 'internal', 'Canonical graph PHP artifact.'),
            $this->surfaceEntry('generated_metadata', 'app/.foundry/build/projections/*.php', 'internal_api', 'internal', 'Generated projection artifacts used by the runtime.'),
            $this->surfaceEntry('generated_metadata', 'app/.foundry/build/manifests/compile_manifest.json', 'internal_api', 'internal', 'Compiler manifest artifact.'),
            $this->surfaceEntry('generated_metadata', 'app/.foundry/build/manifests/compile_cache.json', 'internal_api', 'internal', 'Deterministic compile cache metadata artifact.'),
            $this->surfaceEntry('generated_metadata', 'app/.foundry/build/manifests/config_schemas.json', 'internal_api', 'internal', 'Machine-readable config schema artifact.'),
            $this->surfaceEntry('generated_metadata', 'app/.foundry/build/manifests/integrity_hashes.json', 'internal_api', 'internal', 'Compiler integrity hash artifact.'),
            $this->surfaceEntry('generated_metadata', 'app/.foundry/build/diagnostics/latest.json', 'internal_api', 'internal', 'Latest compiler diagnostics artifact.'),
            $this->surfaceEntry('generated_metadata', 'app/.foundry/build/diagnostics/config_validation.json', 'internal_api', 'internal', 'Latest config validation diagnostics artifact.'),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function surfaceEntry(
        string $kind,
        string $identifier,
        string $classification,
        string $stability,
        string $summary,
    ): array {
        return [
            'kind' => $kind,
            'identifier' => $identifier,
            'classification' => $classification,
            'stability' => $stability,
            'safe_to_depend_on' => in_array($classification, ['public_api', 'extension_api'], true) && $stability === 'stable',
            'semver_policy' => $this->semverPolicy($classification, $stability),
            'summary' => $summary,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function cliCommandEntry(
        string $signature,
        string $usage,
        string $stability,
        string $summary,
        string $availability = 'core',
    ): array {
        $classification = match ($stability) {
            'stable' => 'public_api',
            'experimental' => 'experimental_api',
            default => 'internal_api',
        };

        $entry = $this->surfaceEntry('cli_command', $signature, $classification, $stability, $summary);
        $entry['signature'] = $signature;
        $entry['usage'] = $usage;
        $entry['availability'] = $availability;

        return $entry;
    }

    private function semverPolicy(string $classification, string $stability): string
    {
        return match (true) {
            $classification === 'extension_api' => 'Extension API: semver-governed. Breaking changes require upgrade notes, compatibility constraint updates, and migration guidance.',
            $classification === 'public_api' && $stability === 'stable' => 'Public API: semver-governed. Breaking changes require a major release after 1.0 and documented deprecation guidance before then.',
            $classification === 'experimental_api' || $stability === 'experimental' => 'Experimental API: may change in minor releases while clearly marked experimental in docs and CLI output.',
            default => 'Internal API: no compatibility guarantee and may change at any time.',
        };
    }

    /**
     * @param array<string,mixed> $entry
     * @return array<string,mixed>
     */
    private function withMatch(array $entry, string $matchedBy): array
    {
        $entry['matched_by'] = $matchedBy;

        return $entry;
    }

    private function normalizePath(string $path): string
    {
        return str_replace('\\', '/', trim($path));
    }

    private function matchesPattern(string $path, string $pattern): bool
    {
        if ($pattern === '') {
            return false;
        }

        $regex = preg_quote($this->normalizePath($pattern), '#');
        $regex = str_replace('\*\*', '.*', $regex);
        $regex = str_replace('\*', '[^/]+', $regex);

        return preg_match('#^' . $regex . '$#', $path) === 1;
    }
}
