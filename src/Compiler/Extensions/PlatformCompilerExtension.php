<?php
declare(strict_types=1);

namespace Foundry\Compiler\Extensions;

use Foundry\Compiler\Codemod\Codemod;
use Foundry\Compiler\Codemod\PlatformSpecNormalizeCodemod;
use Foundry\Compiler\CompilerPass;
use Foundry\Compiler\Migration\MigrationRule;
use Foundry\Compiler\Migration\SpecFormat;
use Foundry\Compiler\Passes\PlatformSpecPass;
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
            providedPasses: ['platform_specs'],
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
            introducedSpecFormats: [
                'billing_spec',
                'workflow_spec',
                'orchestration_spec',
                'search_spec',
                'stream_spec',
                'locale_spec',
                'roles_spec',
                'policy_spec',
                'inspect_ui_spec',
            ],
            providedMigrationRules: [],
            providedCodemods: ['platform-spec-v1-normalize'],
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
        );
    }

    /**
     * @return array<int,CompilerPass>
     */
    public function linkPasses(): array
    {
        return [new PlatformSpecPass()];
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
     * @return array<int,SpecFormat>
     */
    public function specFormats(): array
    {
        return [
            new SpecFormat('billing_spec', 'Billing provider/plan specs under app/specs/billing/*.billing.yaml', 1, [1]),
            new SpecFormat('workflow_spec', 'Workflow FSM specs under app/specs/workflows/*.workflow.yaml', 1, [1]),
            new SpecFormat('orchestration_spec', 'Orchestration specs under app/specs/orchestrations/*.orchestration.yaml', 1, [1]),
            new SpecFormat('search_spec', 'Search index specs under app/specs/search/*.search.yaml', 1, [1]),
            new SpecFormat('stream_spec', 'Realtime stream specs under app/specs/streams/*.stream.yaml', 1, [1]),
            new SpecFormat('locale_spec', 'Locale bundle specs under app/specs/locales/*.locale.yaml', 1, [1]),
            new SpecFormat('roles_spec', 'Role map specs under app/specs/roles/*.roles.yaml', 1, [1]),
            new SpecFormat('policy_spec', 'Policy map specs under app/specs/policies/*.policy.yaml', 1, [1]),
            new SpecFormat('inspect_ui_spec', 'Inspect UI specs under app/specs/inspect-ui/*.inspect-ui.yaml', 1, [1]),
        ];
    }

    /**
     * @return array<int,Codemod>
     */
    public function codemods(): array
    {
        return [new PlatformSpecNormalizeCodemod()];
    }

    /**
     * @return array<int,PackDefinition>
     */
    public function packs(): array
    {
        return [
            new PackDefinition(name: 'platform.billing', version: '1.0.0', extension: $this->name(), providedCapabilities: ['billing.stripe'], requiredCapabilities: ['compiler.core', 'runtime.pipeline'], generators: ['generate billing stripe'], specFormats: ['billing_spec'], verifiers: ['verify billing']),
            new PackDefinition(name: 'platform.workflows', version: '1.0.0', extension: $this->name(), providedCapabilities: ['workflow.fsm'], requiredCapabilities: ['compiler.core'], generators: ['generate workflow <name> --spec=<file>'], specFormats: ['workflow_spec'], verifiers: ['verify workflows']),
            new PackDefinition(name: 'platform.orchestration', version: '1.0.0', extension: $this->name(), providedCapabilities: ['orchestration.graph'], requiredCapabilities: ['compiler.core', 'workflow.fsm'], generators: ['generate orchestration <name> --spec=<file>'], specFormats: ['orchestration_spec'], verifiers: ['verify orchestrations']),
            new PackDefinition(name: 'platform.search', version: '1.0.0', extension: $this->name(), providedCapabilities: ['search.adapters'], requiredCapabilities: ['compiler.core'], generators: ['generate search-index <name> --spec=<file>'], specFormats: ['search_spec'], verifiers: ['verify search']),
            new PackDefinition(name: 'platform.streams', version: '1.0.0', extension: $this->name(), providedCapabilities: ['streams.sse'], requiredCapabilities: ['compiler.core', 'runtime.pipeline'], generators: ['generate stream <name>'], specFormats: ['stream_spec'], verifiers: ['verify streams']),
            new PackDefinition(name: 'platform.locales', version: '1.0.0', extension: $this->name(), providedCapabilities: ['localization.i18n'], requiredCapabilities: ['compiler.core'], generators: ['generate locale <locale>'], specFormats: ['locale_spec'], verifiers: ['verify locales']),
            new PackDefinition(name: 'platform.roles', version: '1.0.0', extension: $this->name(), providedCapabilities: ['auth.roles_policies'], requiredCapabilities: ['compiler.core'], generators: ['generate roles', 'generate policy <name>'], specFormats: ['roles_spec', 'policy_spec'], verifiers: ['verify policies']),
            new PackDefinition(name: 'platform.inspect_ui', version: '1.0.0', extension: $this->name(), providedCapabilities: ['inspect.ui'], requiredCapabilities: ['compiler.core'], generators: ['generate inspect-ui'], specFormats: ['inspect_ui_spec'], verifiers: ['verify graph']),
        ];
    }
}
