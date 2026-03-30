<?php

declare(strict_types=1);

namespace Foundry\Documentation;

use Foundry\Compiler\ApplicationGraph;
use Foundry\Compiler\Extensions\ExtensionRegistry;
use Foundry\Compiler\IR\GraphNode;
use Foundry\Support\ApiSurfaceRegistry;
use Foundry\Support\CliCommandPrefix;
use Foundry\Support\Paths;

final class CommandCatalog
{
    public function __construct(
        private readonly Paths $paths,
        private readonly ApiSurfaceRegistry $apiSurfaceRegistry,
    ) {}

    /**
     * @return array<string,mixed>
     */
    public function data(ApplicationGraph $graph): array
    {
        $commands = array_values($this->apiSurfaceRegistry->cliCommands());
        $availableExtensions = $this->availableExtensions($graph);
        $availablePipelineStages = $this->availablePipelineStages();

        $rows = [];
        foreach ($commands as $command) {
            if (!is_array($command)) {
                continue;
            }

            $rows[] = $this->commandRow($command, $graph, $commands, $availableExtensions, $availablePipelineStages);
        }

        return [
            'summary' => $this->apiSurfaceRegistry->cliHelpIndex()['summary'] ?? [],
            'filters' => [
                'categories' => $this->commandCategories($rows),
                'command_types' => $this->commandTypes($rows),
                'extensions' => $availableExtensions,
                'pipeline_stages' => $availablePipelineStages,
            ],
            'commands' => $rows,
        ];
    }

    /**
     * @param array<string,mixed> $command
     * @param array<int,array<string,mixed>> $allCommands
     * @param array<int,string> $availableExtensions
     * @param array<int,string> $availablePipelineStages
     * @return array<string,mixed>
     */
    private function commandRow(
        array $command,
        ApplicationGraph $graph,
        array $allCommands,
        array $availableExtensions,
        array $availablePipelineStages,
    ): array {
        $signature = (string) ($command['signature'] ?? '');
        $usage = (string) ($command['usage'] ?? '');
        $description = (string) ($command['summary'] ?? '');
        $preview = $this->sampleOutputPreview($command, $graph);
        $docs = $this->docsLinks($signature);
        $relatedNodes = $this->relatedGraphNodes($signature, $graph);
        $supportsPipelineStageFilter = (bool) ($command['supports_pipeline_stage_filter'] ?? false);
        $supportsExtensionFilter = (bool) ($command['supports_extension_filter'] ?? false);
        $pipelineStages = $supportsPipelineStageFilter ? $availablePipelineStages : [];
        $extensions = $supportsExtensionFilter ? $availableExtensions : [];
        $category = (string) ($command['category'] ?? 'Architecture');
        $commandType = (string) ($command['command_type'] ?? trim((string) strtok($signature, ' ')));

        return [
            'signature' => $signature,
            'usage' => $usage,
            'description' => $description,
            'category' => $category,
            'commandType' => $commandType,
            'stability' => (string) ($command['stability'] ?? 'internal'),
            'availability' => (string) ($command['availability'] ?? 'core'),
            'classification' => (string) ($command['classification'] ?? 'internal_api'),
            'semverPolicy' => (string) ($command['semver_policy'] ?? ''),
            'supportsPipelineStageFilter' => $supportsPipelineStageFilter,
            'supportsExtensionFilter' => $supportsExtensionFilter,
            'pipelineStages' => $pipelineStages,
            'extensions' => $extensions,
            'playgroundHref' => 'command-playground.html?command=' . rawurlencode($signature),
            'examples' => $this->usageExamples($signature, $graph, $availableExtensions, $availablePipelineStages),
            'sampleOutputLabel' => (string) ($preview['label'] ?? 'Sample JSON output'),
            'sampleOutput' => $preview['payload'] ?? [],
            'docs' => $docs,
            'explainTargets' => $this->relatedExplainTargets($signature, $relatedNodes),
            'relatedCommands' => $this->relatedCommands($signature, $allCommands),
            'relatedNodes' => $relatedNodes,
            'searchText' => strtolower(implode(' ', array_filter([
                $signature,
                $usage,
                $description,
                $category,
                $commandType,
                (string) ($command['stability'] ?? ''),
                (string) ($command['availability'] ?? ''),
                implode(' ', $pipelineStages),
                implode(' ', $extensions),
                implode(' ', array_map(static fn(array $doc): string => (string) ($doc['title'] ?? ''), $docs)),
            ]))),
        ];
    }

    /**
     * @param array<int,array<string,mixed>> $commands
     * @return array<int,string>
     */
    private function commandCategories(array $commands): array
    {
        $categories = [];

        foreach ($commands as $command) {
            $category = trim((string) ($command['category'] ?? ''));
            if ($category !== '') {
                $categories[$category] = true;
            }
        }

        $categories = array_keys($categories);
        sort($categories);

        return $categories;
    }

    /**
     * @param array<int,array<string,mixed>> $commands
     * @return array<int,string>
     */
    private function commandTypes(array $commands): array
    {
        $types = [];

        foreach ($commands as $command) {
            $type = trim((string) ($command['commandType'] ?? ''));
            if ($type !== '') {
                $types[$type] = true;
            }
        }

        $types = array_keys($types);
        sort($types);

        return $types;
    }

    /**
     * @return array<int,string>
     */
    private function usageExamples(
        string $signature,
        ApplicationGraph $graph,
        array $availableExtensions,
        array $availablePipelineStages,
    ): array {
        $prefix = CliCommandPrefix::foundry($this->paths);
        $examples = [
            $prefix . ' ' . $this->sampleInvocation($signature, $graph, $availableExtensions, $availablePipelineStages),
            $prefix . ' ' . $this->helpInvocation($signature),
        ];

        return array_values(array_unique(array_filter(array_map('trim', $examples))));
    }

    /**
     * @param array<int,string> $availableExtensions
     * @param array<int,string> $availablePipelineStages
     */
    private function sampleInvocation(
        string $signature,
        ApplicationGraph $graph,
        array $availableExtensions,
        array $availablePipelineStages,
    ): string {
        $examples = $this->exampleValues($graph, $availableExtensions, $availablePipelineStages);

        return match (true) {
            $signature === 'help' => 'help inspect graph --json',
            $signature === 'compile graph' => 'compile graph --json',
            $signature === 'cache inspect' => 'cache inspect --json',
            $signature === 'cache clear' => 'cache clear --json',
            $signature === 'doctor' => 'doctor --graph --json',
            $signature === 'upgrade-check' => 'upgrade-check --json',
            $signature === 'observe:trace' => 'observe:trace ' . $examples['feature'] . ' --json',
            $signature === 'observe:profile' => 'observe:profile ' . $examples['feature'] . ' --json',
            $signature === 'observe:compare' => 'observe:compare run-a run-b --json',
            $signature === 'history' => 'history --json',
            $signature === 'regressions' => 'regressions --json',
            $signature === 'graph inspect' => 'graph inspect --format=json --json',
            $signature === 'graph visualize' => 'graph visualize --format=svg --json',
            $signature === 'prompt' => 'prompt Plan the ' . $examples['feature'] . ' feature --dry-run --json',
            $signature === 'license status' => 'license status --json',
            $signature === 'license activate' => 'license activate --key=YOUR_KEY --json',
            $signature === 'license deactivate' => 'license deactivate --json',
            $signature === 'features' => 'features --json',
            $signature === 'explain' => 'explain ' . $examples['feature'] . ' --json',
            $signature === 'diff' => 'diff --json',
            $signature === 'trace' => 'trace ' . $examples['feature'] . ' --json',
            $signature === 'generate <prompt>' => 'generate Add tags to ' . $examples['feature'] . ' --feature-context --deterministic --dry-run --json',
            $signature === 'serve' => 'serve --json',
            $signature === 'queue:work' => 'queue:work default --json',
            $signature === 'queue:inspect' => 'queue:inspect default --json',
            $signature === 'schedule:run' => 'schedule:run --json',
            $signature === 'trace:tail' => 'trace:tail --json',
            $signature === 'affected-files' => 'affected-files ' . $examples['feature'] . ' --json',
            $signature === 'impacted-features' => 'impacted-features event:' . $examples['event'] . ' --json',
            $signature === 'new' => 'new demo-app --starter=standard --json',
            $signature === 'init app' => 'init app demo-app --starter=standard --json',
            $signature === 'migrate definitions' => 'migrate definitions --dry-run --json',
            $signature === 'codemod run' => 'codemod run example-codemod --dry-run --json',
            $signature === 'export graph' => 'export graph --format=json --json',
            $signature === 'export openapi' => 'export openapi --format=json --json',
            $signature === 'preview notification' => 'preview notification welcome_email --json',
            str_starts_with($signature, 'inspect node') => 'inspect node ' . $examples['node'] . ' --json',
            str_starts_with($signature, 'inspect dependents') => 'inspect dependents ' . $examples['node'] . ' --json',
            str_starts_with($signature, 'inspect affected-tests') => 'inspect affected-tests ' . $examples['node'] . ' --json',
            str_starts_with($signature, 'inspect affected-features') => 'inspect affected-features ' . $examples['node'] . ' --json',
            str_starts_with($signature, 'inspect dependencies') => 'inspect dependencies ' . $examples['feature'] . ' --json',
            str_starts_with($signature, 'inspect execution-plan') => 'inspect execution-plan ' . $examples['feature'] . ' --json',
            str_starts_with($signature, 'inspect guards') => 'inspect guards ' . $examples['feature'] . ' --json',
            str_starts_with($signature, 'inspect interceptors') => 'inspect interceptors --stage=' . $examples['pipeline_stage'] . ' --json',
            str_starts_with($signature, 'inspect impact') => 'inspect impact ' . $examples['node'] . ' --json',
            str_starts_with($signature, 'inspect subgraph') => 'inspect subgraph ' . $examples['feature'] . ' --json',
            str_starts_with($signature, 'inspect extension') => 'inspect extension ' . $examples['extension'] . ' --json',
            str_starts_with($signature, 'inspect pack') => 'inspect pack ' . $examples['pack'] . ' --json',
            str_starts_with($signature, 'inspect definition-format') => 'inspect definition-format workflow --json',
            str_starts_with($signature, 'inspect api-surface') => 'inspect api-surface --command=compile graph --json',
            str_starts_with($signature, 'inspect cli-surface') => 'inspect cli-surface --json',
            $signature === 'inspect graph' => 'inspect graph --json',
            $signature === 'inspect build' => 'inspect build --json',
            $signature === 'inspect pipeline' => 'inspect pipeline --json',
            $signature === 'inspect extensions' => 'inspect extensions --json',
            $signature === 'inspect packs' => 'inspect packs --json',
            $signature === 'inspect compatibility' => 'inspect compatibility --json',
            $signature === 'inspect migrations' => 'inspect migrations --json',
            $signature === 'inspect graph-spec' => 'inspect graph-spec --json',
            $signature === 'inspect node-types' => 'inspect node-types --json',
            $signature === 'inspect edge-types' => 'inspect edge-types --json',
            $signature === 'inspect graph-integrity' => 'inspect graph-integrity --json',
            $signature === 'inspect feature' => 'inspect feature ' . $examples['feature'] . ' --json',
            $signature === 'inspect auth' => 'inspect auth ' . $examples['feature'] . ' --json',
            $signature === 'inspect cache' => 'inspect cache ' . $examples['feature'] . ' --json',
            $signature === 'inspect events' => 'inspect events ' . $examples['feature'] . ' --json',
            $signature === 'inspect jobs' => 'inspect jobs ' . $examples['feature'] . ' --json',
            $signature === 'inspect context' => 'inspect context ' . $examples['feature'] . ' --json',
            $signature === 'inspect notification' => 'inspect notification welcome_email --json',
            $signature === 'inspect api' => 'inspect api posts --json',
            $signature === 'inspect resource' => 'inspect resource posts --json',
            $signature === 'inspect route' => 'inspect route ' . $examples['route_method'] . ' ' . $examples['route_path'] . ' --json',
            $signature === 'inspect billing' => 'inspect billing --provider=stripe --json',
            $signature === 'inspect workflow' => 'inspect workflow ' . $examples['workflow'] . ' --json',
            $signature === 'inspect orchestration' => 'inspect orchestration publish_flow --json',
            $signature === 'inspect search' => 'inspect search posts --json',
            $signature === 'inspect streams' => 'inspect streams --json',
            $signature === 'inspect locales' => 'inspect locales --json',
            $signature === 'inspect roles' => 'inspect roles --json',
            $signature === 'generate feature' => 'generate feature definitions/list-posts.yaml --json',
            $signature === 'generate tests' => 'generate tests ' . $examples['feature'] . ' --json',
            $signature === 'generate context' => 'generate context ' . $examples['feature'] . ' --json',
            $signature === 'generate starter' => 'generate starter server-rendered --force --json',
            $signature === 'generate resource' => 'generate resource posts --definition=definitions/posts.resource.yaml --json',
            $signature === 'generate admin-resource' => 'generate admin-resource posts --force --json',
            $signature === 'generate uploads' => 'generate uploads images --force --json',
            $signature === 'generate notification' => 'generate notification welcome_email --force --json',
            $signature === 'generate api-resource' => 'generate api-resource posts --definition=definitions/posts.api-resource.yaml --json',
            $signature === 'generate docs' => 'generate docs --format=markdown --json',
            $signature === 'generate indexes' => 'generate indexes --json',
            $signature === 'generate migration' => 'generate migration definitions/posts.migration.yaml --json',
            $signature === 'generate billing' => 'generate billing stripe --force --json',
            $signature === 'generate workflow' => 'generate workflow ' . $examples['workflow'] . ' --definition=definitions/' . $examples['workflow'] . '.workflow.yaml --json',
            $signature === 'generate orchestration' => 'generate orchestration publish_flow --definition=definitions/publish_flow.orchestration.yaml --json',
            $signature === 'generate search-index' => 'generate search-index posts --definition=definitions/posts.search.yaml --json',
            $signature === 'generate stream' => 'generate stream posts --force --json',
            $signature === 'generate locale' => 'generate locale en_CA --force --json',
            $signature === 'generate roles' => 'generate roles --force --json',
            $signature === 'generate policy' => 'generate policy manage_posts --force --json',
            $signature === 'generate inspect-ui' => 'generate inspect-ui --json',
            str_starts_with($signature, 'verify feature') => 'verify feature ' . $examples['feature'] . ' --json',
            str_starts_with($signature, 'verify resource') => 'verify resource posts --json',
            $signature === 'verify graph' => 'verify graph --json',
            $signature === 'verify graph-integrity' => 'verify graph-integrity --json',
            $signature === 'verify pipeline' => 'verify pipeline --json',
            $signature === 'verify extensions' => 'verify extensions --json',
            $signature === 'verify compatibility' => 'verify compatibility --json',
            $signature === 'verify notifications' => 'verify notifications --json',
            $signature === 'verify api' => 'verify api --json',
            $signature === 'verify billing' => 'verify billing --json',
            $signature === 'verify workflows' => 'verify workflows --json',
            $signature === 'verify orchestrations' => 'verify orchestrations --json',
            $signature === 'verify search' => 'verify search --json',
            $signature === 'verify streams' => 'verify streams --json',
            $signature === 'verify locales' => 'verify locales --json',
            $signature === 'verify policies' => 'verify policies --json',
            $signature === 'verify contracts' => 'verify contracts --json',
            $signature === 'verify cli-surface' => 'verify cli-surface --json',
            $signature === 'verify auth' => 'verify auth --json',
            $signature === 'verify cache' => 'verify cache --json',
            $signature === 'verify events' => 'verify events --json',
            $signature === 'verify jobs' => 'verify jobs --json',
            $signature === 'verify migrations' => 'verify migrations --json',
            default => $signature . ' --json',
        };
    }

    private function helpInvocation(string $signature): string
    {
        return match ($signature) {
            'generate <prompt>' => 'help generate Add --json',
            default => 'help ' . $signature . ' --json',
        };
    }

    /**
     * @param array<string,mixed> $command
     * @return array{label:string,payload:array<string,mixed>}
     */
    private function sampleOutputPreview(array $command, ApplicationGraph $graph): array
    {
        $signature = (string) ($command['signature'] ?? '');

        return match ($signature) {
            'help' => [
                'label' => 'Sample command JSON output (`help --json` index)',
                'payload' => $this->apiSurfaceRegistry->cliHelpIndex(),
            ],
            'inspect graph', 'graph inspect' => [
                'label' => 'Sample command JSON output',
                'payload' => $this->inspectGraphPreview($graph),
            ],
            'generate docs' => [
                'label' => 'Sample command JSON output',
                'payload' => [
                    'format' => 'markdown',
                    'directory' => 'docs/generated',
                    'files' => [
                        'docs/generated/api-surface.md',
                        'docs/generated/cli-reference.md',
                        'docs/generated/features.md',
                        'docs/generated/graph-overview.md',
                        'docs/generated/routes.md',
                    ],
                ],
            ],
            default => [
                'label' => 'Sample `help <command> --json` output',
                'payload' => ['command' => $command],
            ],
        };
    }

    /**
     * @return array<string,mixed>
     */
    private function inspectGraphPreview(ApplicationGraph $graph): array
    {
        return [
            'framework_version' => $graph->frameworkVersion(),
            'graph_version' => $graph->graphVersion(),
            'compiled_at' => $graph->compiledAt(),
            'source_hash' => $graph->sourceHash(),
            'features' => $graph->features(),
            'node_counts' => $graph->nodeCountsByType(),
            'edge_counts' => $graph->edgeCountsByType(),
        ];
    }

    /**
     * @return array<int,array<string,string>>
     */
    private function docsLinks(string $signature): array
    {
        $links = [
            ['title' => 'CLI Reference', 'href' => 'cli-reference.html'],
        ];

        if (
            in_array($signature, ['compile graph', 'graph inspect', 'graph visualize', 'inspect graph', 'export graph', 'verify graph', 'verify graph-integrity', 'verify pipeline', 'doctor', 'prompt', 'explain', 'diff', 'trace', 'observe:trace', 'observe:profile', 'observe:compare'], true)
            || str_starts_with($signature, 'inspect ')
        ) {
            $links[] = ['title' => 'Architecture Tools', 'href' => 'architecture-tools.html'];
            $links[] = ['title' => 'Architecture Explorer', 'href' => 'architecture-explorer.html'];
        }

        if (
            in_array($signature, ['new', 'init app', 'generate starter', 'generate resource', 'generate admin-resource', 'generate uploads', 'generate notification', 'generate api-resource', 'generate billing', 'generate workflow', 'generate orchestration', 'generate search-index', 'generate stream', 'generate locale', 'generate roles', 'generate policy'], true)
        ) {
            $links[] = ['title' => 'App Scaffolding', 'href' => 'app-scaffolding.html'];
            $links[] = ['title' => 'Example Applications', 'href' => 'example-applications.html'];
        }

        if (
            in_array($signature, ['generate docs', 'help', 'inspect cli-surface', 'verify cli-surface'], true)
        ) {
            $links[] = ['title' => 'Reference', 'href' => 'reference.html'];
        }

        if (in_array($signature, ['upgrade-check', 'migrate definitions', 'codemod run', 'verify compatibility'], true)) {
            $links[] = ['title' => 'Upgrade Reference', 'href' => 'upgrade-reference.html'];
            $links[] = ['title' => 'Upgrade Safety', 'href' => 'upgrade-safety.html'];
        }

        $unique = [];
        foreach ($links as $link) {
            $href = (string) ($link['href'] ?? '');
            if ($href === '' || isset($unique[$href])) {
                continue;
            }

            $unique[$href] = $link;
        }

        return array_values($unique);
    }

    /**
     * @param array<int,array<string,string>> $relatedNodes
     * @return array<int,array<string,string>>
     */
    private function relatedExplainTargets(string $signature, array $relatedNodes): array
    {
        $targets = [
            [
                'title' => 'command:' . $signature,
                'href' => 'architecture-tools.html',
                'meta' => 'Command explain target',
            ],
        ];

        foreach ($relatedNodes as $node) {
            $target = (string) ($node['explainTarget'] ?? '');
            if ($target === '') {
                continue;
            }

            $targets[] = [
                'title' => $target,
                'href' => 'architecture-tools.html',
                'meta' => 'Graph explain target',
            ];
        }

        $unique = [];
        foreach ($targets as $target) {
            $title = (string) ($target['title'] ?? '');
            if ($title === '' || isset($unique[$title])) {
                continue;
            }

            $unique[$title] = $target;
        }

        return array_values($unique);
    }

    /**
     * @param array<int,array<string,mixed>> $allCommands
     * @return array<int,array<string,string>>
     */
    private function relatedCommands(string $signature, array $allCommands): array
    {
        $desired = match ($signature) {
            'compile graph' => ['inspect graph', 'graph inspect', 'verify graph', 'export graph'],
            'inspect graph', 'graph inspect' => ['graph visualize', 'compile graph', 'verify graph', 'export graph'],
            'graph visualize' => ['graph inspect', 'export graph', 'inspect graph'],
            'export graph' => ['graph inspect', 'graph visualize', 'compile graph'],
            'new' => ['init app', 'generate docs', 'compile graph'],
            'init app' => ['new', 'generate docs', 'compile graph'],
            'help' => ['inspect cli-surface', 'verify cli-surface', 'explain'],
            'generate docs' => ['graph inspect', 'inspect graph', 'help'],
            'explain' => ['doctor', 'graph inspect', 'inspect graph', 'trace'],
            default => $this->familyRelatedSignatures($signature, $allCommands),
        };

        $available = [];
        foreach ($allCommands as $command) {
            if (!is_array($command)) {
                continue;
            }

            $candidate = (string) ($command['signature'] ?? '');
            if ($candidate !== '') {
                $available[$candidate] = true;
            }
        }

        $rows = [];
        foreach ($desired as $candidate) {
            if ($candidate === $signature || !isset($available[$candidate])) {
                continue;
            }

            $rows[] = ['signature' => $candidate];
            if (count($rows) === 4) {
                break;
            }
        }

        return $rows;
    }

    /**
     * @param array<int,array<string,mixed>> $allCommands
     * @return array<int,string>
     */
    private function familyRelatedSignatures(string $signature, array $allCommands): array
    {
        $family = $this->commandFamily($signature);
        if ($family === '') {
            return [];
        }

        $matches = [];
        foreach ($allCommands as $command) {
            if (!is_array($command)) {
                continue;
            }

            $candidate = (string) ($command['signature'] ?? '');
            if ($candidate === '' || $candidate === $signature) {
                continue;
            }

            if ($this->commandFamily($candidate) === $family) {
                $matches[] = $candidate;
            }
        }

        sort($matches);

        return array_slice($matches, 0, 4);
    }

    private function commandFamily(string $signature): string
    {
        return match (true) {
            str_starts_with($signature, 'inspect ') => 'inspect',
            str_starts_with($signature, 'verify ') => 'verify',
            str_starts_with($signature, 'generate ') => 'generate',
            str_starts_with($signature, 'graph ') => 'graph',
            str_starts_with($signature, 'export ') => 'export',
            str_starts_with($signature, 'pro') => 'pro',
            str_starts_with($signature, 'observe:') => 'observe',
            default => strtok($signature, ' ') ?: '',
        };
    }

    /**
     * @return array<int,array<string,string>>
     */
    private function relatedGraphNodes(string $signature, ApplicationGraph $graph): array
    {
        return match (true) {
            in_array($signature, ['compile graph', 'inspect graph', 'graph inspect', 'graph visualize', 'export graph', 'verify graph', 'verify graph-integrity', 'verify pipeline', 'verify contracts', 'doctor', 'prompt', 'explain', 'diff', 'trace', 'generate docs'], true)
                => $this->pickNodeLinks($graph, ['feature', 'route', 'event'], 3),
            in_array($signature, ['inspect feature', 'inspect auth', 'inspect cache', 'inspect events', 'inspect jobs', 'inspect context', 'inspect execution-plan', 'inspect subgraph', 'observe:trace', 'observe:profile', 'affected-files', 'verify feature', 'verify auth'], true)
                => $this->pickNodeLinks($graph, ['feature'], 2),
            in_array($signature, ['impacted-features'], true)
                => $this->pickNodeLinks($graph, ['event', 'cache'], 2),
            in_array($signature, ['inspect route'], true)
                => $this->pickNodeLinks($graph, ['route'], 2),
            in_array($signature, ['verify events'], true)
                => $this->pickNodeLinks($graph, ['event'], 2),
            in_array($signature, ['verify jobs'], true)
                => $this->pickNodeLinks($graph, ['job'], 2),
            in_array($signature, ['verify cache'], true)
                => $this->pickNodeLinks($graph, ['cache'], 2),
            in_array($signature, ['inspect workflow', 'verify workflows', 'generate workflow'], true)
                => $this->pickNodeLinks($graph, ['workflow'], 2),
            in_array($signature, ['inspect extension', 'inspect extensions', 'verify extensions'], true)
                => $this->pickNodeLinks($graph, ['extension'], 2),
            default => [],
        };
    }

    /**
     * @param array<int,string> $types
     * @return array<int,array<string,string>>
     */
    private function pickNodeLinks(ApplicationGraph $graph, array $types, int $limit): array
    {
        $rows = [];

        foreach ($types as $type) {
            foreach ($graph->nodesByType($type) as $node) {
                $rows[] = [
                    'title' => $this->nodeLabel($node),
                    'href' => 'architecture-explorer.html?node=' . rawurlencode($node->id()),
                    'meta' => $node->type(),
                    'explainTarget' => $this->nodeExplainTarget($node),
                ];

                if (count($rows) === $limit) {
                    return $rows;
                }
            }
        }

        return $rows;
    }

    private function nodeLabel(GraphNode $node): string
    {
        $payload = $node->payload();

        return match ($node->type()) {
            'feature' => (string) ($payload['feature'] ?? $node->id()),
            'route' => (string) ($payload['signature'] ?? $node->id()),
            'event' => (string) ($payload['name'] ?? $node->id()),
            'job' => (string) ($payload['name'] ?? $node->id()),
            'schema' => (string) ($payload['path'] ?? $node->id()),
            'cache' => (string) ($payload['key'] ?? $node->id()),
            'workflow' => (string) ($payload['resource'] ?? $payload['name'] ?? $node->id()),
            'extension' => (string) ($payload['name'] ?? $node->id()),
            default => $node->id(),
        };
    }

    private function nodeExplainTarget(GraphNode $node): string
    {
        $payload = $node->payload();

        return match ($node->type()) {
            'feature' => 'feature:' . (string) ($payload['feature'] ?? $node->id()),
            'route' => 'route:' . (string) ($payload['signature'] ?? $node->id()),
            'event' => 'event:' . (string) ($payload['name'] ?? $node->id()),
            'job' => 'job:' . (string) ($payload['name'] ?? $node->id()),
            'schema' => 'schema:' . (string) ($payload['path'] ?? $node->id()),
            'workflow' => 'workflow:' . (string) ($payload['resource'] ?? $payload['name'] ?? $node->id()),
            'extension' => 'extension:' . (string) ($payload['name'] ?? $node->id()),
            default => '',
        };
    }

    /**
     * @param array<int,string> $availableExtensions
     * @param array<int,string> $availablePipelineStages
     * @return array<string,string>
     */
    private function exampleValues(
        ApplicationGraph $graph,
        array $availableExtensions,
        array $availablePipelineStages,
    ): array {
        $featureNode = $this->firstNode($graph, 'feature');
        $routeNode = $this->firstNode($graph, 'route');
        $eventNode = $this->firstNode($graph, 'event');
        $workflowNode = $this->firstNode($graph, 'workflow');
        $extensionNode = $this->firstNode($graph, 'extension');
        $schemaNode = $this->firstNode($graph, 'schema');
        $firstNode = $graph->nodes();
        $firstNode = $firstNode !== [] ? reset($firstNode) : null;

        $routeSignature = $routeNode instanceof GraphNode ? (string) (($routeNode->payload()['signature'] ?? '') ?: 'GET /posts') : 'GET /posts';
        [$routeMethod, $routePath] = $this->splitRouteSignature($routeSignature);
        $extension = $extensionNode instanceof GraphNode
            ? (string) (($extensionNode->payload()['name'] ?? '') ?: '')
            : '';
        $extension = $extension !== '' ? $extension : (string) ($availableExtensions[0] ?? 'example.extension');
        $pipelineStage = (string) ($availablePipelineStages[0] ?? 'auth');

        return [
            'feature' => $featureNode instanceof GraphNode ? (string) (($featureNode->payload()['feature'] ?? '') ?: 'publish_post') : 'publish_post',
            'route_method' => $routeMethod,
            'route_path' => $routePath,
            'event' => $eventNode instanceof GraphNode ? (string) (($eventNode->payload()['name'] ?? '') ?: 'post.created') : 'post.created',
            'workflow' => $workflowNode instanceof GraphNode ? (string) (($workflowNode->payload()['resource'] ?? $workflowNode->payload()['name'] ?? '') ?: 'editorial') : 'editorial',
            'extension' => $extension,
            'pack' => $extension . '.pack',
            'pipeline_stage' => $pipelineStage,
            'node' => $firstNode instanceof GraphNode ? $firstNode->id() : 'feature:publish_post',
            'schema' => $schemaNode instanceof GraphNode ? (string) (($schemaNode->payload()['path'] ?? '') ?: 'app/features/publish_post/input.schema.json') : 'app/features/publish_post/input.schema.json',
        ];
    }

    private function firstNode(ApplicationGraph $graph, string $type): ?GraphNode
    {
        $nodes = $graph->nodesByType($type);
        $node = $nodes !== [] ? reset($nodes) : null;

        return $node instanceof GraphNode ? $node : null;
    }

    /**
     * @return array{0:string,1:string}
     */
    private function splitRouteSignature(string $signature): array
    {
        $signature = trim($signature);
        if ($signature === '' || !str_contains($signature, ' ')) {
            return ['GET', '/'];
        }

        [$method, $path] = explode(' ', $signature, 2);

        return [strtoupper(trim($method)), trim($path)];
    }

    /**
     * @return array<int,string>
     */
    private function availableExtensions(ApplicationGraph $graph): array
    {
        $extensions = [];

        foreach ($graph->nodes() as $node) {
            $name = trim((string) ($node->payload()['extension'] ?? $node->payload()['name'] ?? ''));
            if ($node->type() === 'extension' && $name !== '') {
                $extensions[$name] = true;
            }
        }

        foreach (ExtensionRegistry::forPaths($this->paths)->inspectRows() as $row) {
            $name = trim((string) ($row['name'] ?? ''));
            if ($name !== '') {
                $extensions[$name] = true;
            }
        }

        $extensions = array_keys($extensions);
        sort($extensions);

        return $extensions;
    }

    /**
     * @return array<int,string>
     */
    private function availablePipelineStages(): array
    {
        $stages = [];

        foreach (ExtensionRegistry::forPaths($this->paths)->pipelineStages() as $stage) {
            $name = trim((string) $stage->name);
            if ($name !== '') {
                $stages[$name] = true;
            }
        }

        $stages = array_keys($stages);
        sort($stages);

        return $stages;
    }
}
