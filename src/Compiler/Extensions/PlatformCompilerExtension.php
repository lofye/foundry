<?php
declare(strict_types=1);

namespace Foundry\Compiler\Extensions;

use Foundry\Compiler\Codemod\Codemod;
use Foundry\Compiler\Codemod\PlatformDefinitionNormalizeCodemod;
use Foundry\Compiler\CompilerPass;
use Foundry\Compiler\Migration\MigrationRule;
use Foundry\Compiler\Migration\DefinitionFormat;
use Foundry\Compiler\Passes\PlatformDefinitionPass;
use Foundry\Compiler\Projection\PlatformProjectionEmitters;
use Foundry\Compiler\Projection\ProjectionEmitter;

final class PlatformCompilerExtension extends AbstractCompilerExtension
{
    public function name(): string
    {
        return 'platform';
    }

    public function version(): string
    {
        return '1.0.0';
    }

    public function descriptor(): ExtensionDescriptor
    {
        return new ExtensionDescriptor(
            name: $this->name(),
            version: $this->version(),
            description: 'Graph-native billing, workflows, orchestration, search, streams, locales, roles/policies, and inspect-ui foundations.',
            frameworkVersionConstraint: '*',
            graphVersionConstraint: '^1',
            providedNodeTypes: ['billing', 'workflow', 'orchestration', 'search_index', 'stream', 'locale_bundle', 'role', 'policy', 'inspect_ui'],
            providedPasses: ['platform_definitions'],
            providedPacks: [
                'platform.billing',
                'platform.workflows',
                'platform.orchestration',
                'platform.search',
                'platform.streams',
                'platform.locales',
                'platform.roles',
                'platform.inspect_ui',
            ],
            introducedDefinitionFormats: [
                'billing_definition',
                'workflow_definition',
                'orchestration_definition',
                'search_definition',
                'stream_definition',
                'locale_definition',
                'roles_definition',
                'policy_definition',
                'inspect_ui_definition',
            ],
            providedMigrationRules: [],
            providedCodemods: ['platform-definition-v1-normalize'],
            providedProjectionOutputs: [
                'billing_index.php',
                'workflow_index.php',
                'orchestration_index.php',
                'search_index.php',
                'stream_index.php',
                'locale_index.php',
                'role_index.php',
                'policy_index.php',
                'inspect_ui_index.php',
            ],
            providedInspectSurfaces: ['billing', 'workflow', 'orchestration', 'search', 'streams', 'locales', 'roles'],
            providedVerifiers: ['billing', 'workflows', 'orchestrations', 'search', 'streams', 'locales', 'policies'],
            providedCapabilities: [
                'billing.stripe',
                'workflow.fsm',
                'orchestration.graph',
                'search.adapters',
                'streams.sse',
                'localization.i18n',
                'auth.roles_policies',
                'inspect.ui',
            ],
            requiredExtensions: ['core'],
        );
    }

    /**
     * @return array<int,CompilerPass>
     */
    public function linkPasses(): array
    {
        return [new PlatformDefinitionPass()];
    }

    public function passPriority(string $stage, CompilerPass $pass): int
    {
        if ($stage === 'link') {
            return 260;
        }

        return parent::passPriority($stage, $pass);
    }

    /**
     * @return array<int,ProjectionEmitter>
     */
    public function projectionEmitters(): array
    {
        return PlatformProjectionEmitters::all();
    }

    /**
     * @return array<int,MigrationRule>
     */
    public function migrationRules(): array
    {
        return [];
    }

    /**
     * @return array<int,DefinitionFormat>
     */
    public function definitionFormats(): array
    {
        return [
            new DefinitionFormat('billing_definition', 'Billing provider/plan definitions under app/definitions/billing/*.billing.yaml', 1, [1]),
            new DefinitionFormat('workflow_definition', 'Workflow FSM definitions under app/definitions/workflows/*.workflow.yaml', 1, [1]),
            new DefinitionFormat('orchestration_definition', 'Orchestration definitions under app/definitions/orchestrations/*.orchestration.yaml', 1, [1]),
            new DefinitionFormat('search_definition', 'Search index definitions under app/definitions/search/*.search.yaml', 1, [1]),
            new DefinitionFormat('stream_definition', 'Realtime stream definitions under app/definitions/streams/*.stream.yaml', 1, [1]),
            new DefinitionFormat('locale_definition', 'Locale bundle definitions under app/definitions/locales/*.locale.yaml', 1, [1]),
            new DefinitionFormat('roles_definition', 'Role map definitions under app/definitions/roles/*.roles.yaml', 1, [1]),
            new DefinitionFormat('policy_definition', 'Policy map definitions under app/definitions/policies/*.policy.yaml', 1, [1]),
            new DefinitionFormat('inspect_ui_definition', 'Inspect UI definitions under app/definitions/inspect-ui/*.inspect-ui.yaml', 1, [1]),
        ];
    }

    /**
     * @return array<int,Codemod>
     */
    public function codemods(): array
    {
        return [new PlatformDefinitionNormalizeCodemod()];
    }

    /**
     * @return array<int,PackDefinition>
     */
    public function packs(): array
    {
        return [
            new PackDefinition(name: 'platform.billing', version: '1.0.0', extension: $this->name(), providedCapabilities: ['billing.stripe'], requiredCapabilities: ['compiler.core', 'runtime.pipeline'], generators: ['generate billing stripe'], inspectSurfaces: ['billing', 'extensions'], definitionFormats: ['billing_definition'], verifiers: ['verify billing']),
            new PackDefinition(name: 'platform.workflows', version: '1.0.0', extension: $this->name(), providedCapabilities: ['workflow.fsm'], requiredCapabilities: ['compiler.core'], generators: ['generate workflow <name> --definition=<file>'], inspectSurfaces: ['workflow', 'extensions'], definitionFormats: ['workflow_definition'], verifiers: ['verify workflows']),
            new PackDefinition(name: 'platform.orchestration', version: '1.0.0', extension: $this->name(), providedCapabilities: ['orchestration.graph'], requiredCapabilities: ['compiler.core', 'workflow.fsm'], dependencies: ['platform.workflows'], generators: ['generate orchestration <name> --definition=<file>'], inspectSurfaces: ['orchestration', 'extensions'], definitionFormats: ['orchestration_definition'], verifiers: ['verify orchestrations']),
            new PackDefinition(name: 'platform.search', version: '1.0.0', extension: $this->name(), providedCapabilities: ['search.adapters'], requiredCapabilities: ['compiler.core'], generators: ['generate search-index <name> --definition=<file>'], inspectSurfaces: ['search', 'extensions'], definitionFormats: ['search_definition'], verifiers: ['verify search']),
            new PackDefinition(name: 'platform.streams', version: '1.0.0', extension: $this->name(), providedCapabilities: ['streams.sse'], requiredCapabilities: ['compiler.core', 'runtime.pipeline'], generators: ['generate stream <name>'], inspectSurfaces: ['streams', 'extensions'], definitionFormats: ['stream_definition'], verifiers: ['verify streams']),
            new PackDefinition(name: 'platform.locales', version: '1.0.0', extension: $this->name(), providedCapabilities: ['localization.i18n'], requiredCapabilities: ['compiler.core'], generators: ['generate locale <locale>'], inspectSurfaces: ['locales', 'extensions'], definitionFormats: ['locale_definition'], verifiers: ['verify locales']),
            new PackDefinition(name: 'platform.roles', version: '1.0.0', extension: $this->name(), providedCapabilities: ['auth.roles_policies'], requiredCapabilities: ['compiler.core'], generators: ['generate roles', 'generate policy <name>'], inspectSurfaces: ['roles', 'extensions'], definitionFormats: ['roles_definition', 'policy_definition'], verifiers: ['verify policies']),
            new PackDefinition(name: 'platform.inspect_ui', version: '1.0.0', extension: $this->name(), providedCapabilities: ['inspect.ui'], requiredCapabilities: ['compiler.core'], generators: ['generate inspect-ui'], inspectSurfaces: ['graph', 'extensions'], definitionFormats: ['inspect_ui_definition'], verifiers: ['verify graph']),
        ];
    }
}
