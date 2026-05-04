<?php

declare(strict_types=1);

namespace Foundry\CLI;

use Foundry\CLI\Commands\CacheClearCommand;
use Foundry\CLI\Commands\CacheInspectCommand;
use Foundry\CLI\Commands\CodemodRunCommand;
use Foundry\CLI\Commands\CompileGraphCommand;
use Foundry\CLI\Commands\CompletionCommand;
use Foundry\CLI\Commands\ContextCheckAlignmentCommand;
use Foundry\CLI\Commands\ContextDoctorCommand;
use Foundry\CLI\Commands\ContextInitCommand;
use Foundry\CLI\Commands\ContextRepairCommand;
use Foundry\CLI\Commands\DiffCommand;
use Foundry\CLI\Commands\DoctorCommand;
use Foundry\CLI\Commands\ExamplesCommand;
use Foundry\CLI\Commands\EventCommand;
use Foundry\CLI\Commands\ExplainCommand;
use Foundry\CLI\Commands\ExportGraphCommand;
use Foundry\CLI\Commands\ExportOpenApiCommand;
use Foundry\CLI\Commands\FeaturesCommand;
use Foundry\CLI\Commands\FeatureSystemCommand;
use Foundry\CLI\Commands\GenerateCommand as PromptGenerateCommand;
use Foundry\CLI\Commands\GenerateFeatureCommand;
use Foundry\CLI\Commands\GenerateIndexesCommand;
use Foundry\CLI\Commands\GenerateIntegrationCommand;
use Foundry\CLI\Commands\GenerateMetricsCommand;
use Foundry\CLI\Commands\GeneratePlatformCommand;
use Foundry\CLI\Commands\GenerateScaffoldCommand;
use Foundry\CLI\Commands\GraphVisualizeCommand;
use Foundry\CLI\Commands\HistoryCommand;
use Foundry\CLI\Commands\ImpactCommand;
use Foundry\CLI\Commands\ImplementFeatureCommand;
use Foundry\CLI\Commands\ImplementSpecCommand;
use Foundry\CLI\Commands\InitAppCommand;
use Foundry\CLI\Commands\InitCommand;
use Foundry\CLI\Commands\InspectApiCommand;
use Foundry\CLI\Commands\InspectContextCommand;
use Foundry\CLI\Commands\InspectFeatureCommand;
use Foundry\CLI\Commands\InspectGraphCommand;
use Foundry\CLI\Commands\InspectNotificationCommand;
use Foundry\CLI\Commands\InspectPlatformCommand;
use Foundry\CLI\Commands\InspectResourceCommand;
use Foundry\CLI\Commands\InspectRouteCommand;
use Foundry\CLI\Commands\LicenseCommand;
use Foundry\CLI\Commands\McpServeCommand;
use Foundry\CLI\Commands\MigrateDefinitionsCommand;
use Foundry\CLI\Commands\ObserveCommand;
use Foundry\CLI\Commands\PackCommand;
use Foundry\CLI\Commands\PlanListCommand;
use Foundry\CLI\Commands\PlanFeatureCommand;
use Foundry\CLI\Commands\PlanReplayCommand;
use Foundry\CLI\Commands\PlanShowCommand;
use Foundry\CLI\Commands\PlanUndoCommand;
use Foundry\CLI\Commands\PreviewNotificationCommand;
use Foundry\CLI\Commands\PromptCommand;
use Foundry\CLI\Commands\QueueWorkCommand;
use Foundry\CLI\Commands\RegressionsCommand;
use Foundry\CLI\Commands\ScheduleRunCommand;
use Foundry\CLI\Commands\ServeCommand;
use Foundry\CLI\Commands\SpecNewCommand;
use Foundry\CLI\Commands\SpecPlanCommand;
use Foundry\CLI\Commands\SpecLogEntryCommand;
use Foundry\CLI\Commands\SpecValidateCommand;
use Foundry\CLI\Commands\TraceCommand;
use Foundry\CLI\Commands\UpgradeCheckCommand;
use Foundry\CLI\Commands\VerifyCompatibilityCommand;
use Foundry\CLI\Commands\VerifyCoverageCommand;
use Foundry\CLI\Commands\VerifyContextCommand;
use Foundry\CLI\Commands\VerifyContractsCommand;
use Foundry\CLI\Commands\VerifyFeatureCommand;
use Foundry\CLI\Commands\VerifyFeaturesCommand;
use Foundry\CLI\Commands\VerifyGraphCommand;
use Foundry\CLI\Commands\VerifyIntegrationCommand;
use Foundry\CLI\Commands\VerifyPipelineCommand;
use Foundry\CLI\Commands\VerifyPlatformCommand;
use Foundry\CLI\Commands\VerifyResourceCommand;
use Foundry\Support\ApiSurfaceRegistry;
use Foundry\Support\FoundryError;
use Foundry\Support\Json;
use Foundry\UX\FirstRunService;

final class Application
{
    /**
     * @var array<int,Command>
     */
    private array $commands;
    private ApiSurfaceRegistry $apiSurfaceRegistry;
    private ExceptionRenderer $exceptionRenderer;

    public function __construct(?array $commands = null)
    {
        $this->commands = $commands ?? self::registeredCommands();
        $this->apiSurfaceRegistry = new ApiSurfaceRegistry();
        $this->exceptionRenderer = new ExceptionRenderer();
    }

    /**
     * @return list<Command>
     */
    public static function registeredCommands(): array
    {
        return [
            new CompileGraphCommand(),
            new CompletionCommand(),
            new ContextInitCommand(),
            new ContextDoctorCommand(),
            new ContextRepairCommand(),
            new ContextCheckAlignmentCommand(),
            new ImplementFeatureCommand(),
            new ImplementSpecCommand(),
            new PlanFeatureCommand(),
            new PlanListCommand(),
            new PlanReplayCommand(),
            new PlanShowCommand(),
            new PlanUndoCommand(),
            new SpecNewCommand(),
            new SpecPlanCommand(),
            new SpecLogEntryCommand(),
            new SpecValidateCommand(),
            new CacheInspectCommand(),
            new CacheClearCommand(),
            new InspectGraphCommand(),
            new DoctorCommand(),
            new ObserveCommand(),
            new HistoryCommand(),
            new RegressionsCommand(),
            new ExplainCommand(),
            new DiffCommand(),
            new TraceCommand(),
            new GraphVisualizeCommand(),
            new ExportGraphCommand(),
            new PromptCommand(),
            new VerifyGraphCommand(),
            new VerifyPipelineCommand(),
            new VerifyCompatibilityCommand(),
            new VerifyCoverageCommand(),
            new UpgradeCheckCommand(),
            new MigrateDefinitionsCommand(),
            new CodemodRunCommand(),
            new InspectFeatureCommand(),
            new InspectNotificationCommand(),
            new InspectApiCommand(),
            new InspectResourceCommand(),
            new InspectPlatformCommand(),
            new InspectRouteCommand(),
            new InitCommand(),
            new InitAppCommand(),
            new ExamplesCommand(),
            new LicenseCommand(),
            new McpServeCommand(),
            new FeaturesCommand(),
            new FeatureSystemCommand(),
            new EventCommand(),
            new PackCommand(),
            new PromptGenerateCommand(),
            new GenerateMetricsCommand(),
            new GenerateScaffoldCommand(),
            new GenerateIntegrationCommand(),
            new GeneratePlatformCommand(),
            new GenerateFeatureCommand(),
            new GenerateIndexesCommand(),
            new ExportOpenApiCommand(),
            new PreviewNotificationCommand(),
            new InspectContextCommand(),
            new VerifyFeatureCommand(),
            new VerifyFeaturesCommand(),
            new VerifyContextCommand(),
            new VerifyResourceCommand(),
            new VerifyIntegrationCommand(),
            new VerifyPlatformCommand(),
            new VerifyContractsCommand(),
            new ServeCommand(),
            new QueueWorkCommand(),
            new ScheduleRunCommand(),
            new ImpactCommand(),
        ];
    }

    /**
     * @param array<int,string> $argv
     */
    public function run(array $argv): int
    {
        $args = $argv;
        array_shift($args);

        $json = false;
        $args = array_values(array_filter($args, static function (string $arg) use (&$json): bool {
            if ($arg === '--json') {
                $json = true;

                return false;
            }

            return true;
        }));

        $context = new CommandContext(jsonOutput: $json);

        try {
            if ($args === []) {
                return $this->emitResult((new FirstRunService())->run($context), $json);
            }

            if (($args[0] ?? null) === 'help') {
                return $this->emitResult($this->helpResult(array_slice($args, 1), $json), $json);
            }

            $command = array_find(
                $this->commands,
                static fn(Command $candidate): bool => $candidate->matches($args),
            );

            if ($command !== null) {
                $result = $command->run($args, $context);

                return $this->emitResult($result, $json);
            }

            throw new FoundryError('CLI_COMMAND_NOT_FOUND', 'not_found', ['args' => $args], 'Command not found.');
        } catch (\Throwable $error) {
            return $this->emitResult($this->exceptionRenderer->render($error, $json), $json);
        }
    }

    /**
     * @param array{status:int,payload:array<string,mixed>|null,message:string|null} $result
     */
    private function emitResult(array $result, bool $json): int
    {
        if ($json) {
            echo Json::encode($result['payload'] ?? [], true) . PHP_EOL;

            return $result['status'];
        }

        if ($result['message'] !== null && $result['message'] !== '') {
            echo $result['message'] . PHP_EOL;
        }

        if ($result['payload'] !== null && (($result['status'] ?? 0) === 0 || $result['message'] === null || $result['message'] === '')) {
            echo Json::encode($result['payload'], true) . PHP_EOL;
        }

        return $result['status'];
    }

    /**
     * @param array<int,string> $args
     * @return array{status:int,payload:array<string,mixed>|null,message:string|null}
     */
    private function helpResult(array $args, bool $json): array
    {
        if ($args === []) {
            $payload = $this->apiSurfaceRegistry->cliHelpIndex();

            return [
                'status' => 0,
                'message' => $json ? null : $this->renderHelpIndex($payload),
                'payload' => $json ? $payload : null,
            ];
        }

        $command = $this->apiSurfaceRegistry->classifyCliCommand($args);
        if ($command !== null) {
            $payload = ['command' => $command];

            return [
                'status' => 0,
                'message' => $json ? null : $this->renderCommandHelp($command),
                'payload' => $json ? $payload : null,
            ];
        }

        $group = count($args) === 1 ? $this->helpGroupPayload($args[0]) : null;
        if ($group !== null) {
            $payload = ['group' => $group];

            return [
                'status' => 0,
                'message' => $json ? null : $this->renderHelpGroup($group),
                'payload' => $json ? $payload : null,
            ];
        }

        throw new FoundryError('CLI_HELP_COMMAND_NOT_FOUND', 'not_found', ['args' => $args], 'Help target not found.');
    }

    /**
     * @param array<string,mixed> $payload
     */
    private function renderHelpIndex(array $payload): string
    {
        $lines = ['Foundry CLI', ''];

        $groups = is_array($payload['commands'] ?? null) ? $payload['commands'] : [];
        foreach (['stable' => 'Stable', 'experimental' => 'Experimental', 'internal' => 'Internal'] as $key => $label) {
            $lines[] = $label . ' Commands:';
            foreach ((array) ($groups[$key] ?? []) as $entry) {
                if (!is_array($entry)) {
                    continue;
                }

                $lines[] = '- ' . (string) ($entry['signature'] ?? '') . ': ' . (string) ($entry['summary'] ?? '');
            }
            $lines[] = '';
        }

        $lines[] = 'Use `foundry help <command>` for usage, stability, and semver details.';
        $lines[] = 'Use `foundry help inspect`, `foundry help verify`, or `foundry help generate` to browse a command family.';
        $lines[] = 'Run `foundry` or `foundry init` for the deterministic first-run walkthrough.';

        return implode(PHP_EOL, $lines);
    }

    /**
     * @param array<string,mixed> $command
     */
    private function renderCommandHelp(array $command): string
    {
        $availability = (string) ($command['availability'] ?? 'core');
        $lines = [
            'Command: ' . (string) ($command['signature'] ?? ''),
            'Usage: ' . (string) ($command['usage'] ?? ''),
            'Stability: ' . (string) ($command['stability'] ?? 'internal'),
            'Availability: ' . ucfirst($availability),
            'Classification: ' . (string) ($command['classification'] ?? 'internal_api'),
            'Summary: ' . (string) ($command['summary'] ?? ''),
            'Semver: ' . (string) ($command['semver_policy'] ?? ''),
        ];

        return implode(PHP_EOL, $lines);
    }

    /**
     * @return array<string,mixed>|null
     */
    private function helpGroupPayload(string $group): ?array
    {
        $name = strtolower(trim($group));
        if ($name === '') {
            return null;
        }

        $commands = [
            'stable' => [],
            'experimental' => [],
            'internal' => [],
        ];

        $helpIndex = $this->apiSurfaceRegistry->cliHelpIndex();
        foreach ((array) ($helpIndex['commands'] ?? []) as $stability => $entries) {
            if (!array_key_exists($stability, $commands) || !is_array($entries)) {
                continue;
            }

            foreach ($entries as $entry) {
                if (!is_array($entry) || !$this->commandBelongsToGroup($name, $entry)) {
                    continue;
                }

                $commands[$stability][] = $entry;
            }
        }

        foreach ($commands as &$entries) {
            usort(
                $entries,
                static fn(array $a, array $b): int => strcmp((string) ($a['signature'] ?? ''), (string) ($b['signature'] ?? '')),
            );
        }
        unset($entries);

        $counts = [
            'stable' => count($commands['stable']),
            'experimental' => count($commands['experimental']),
            'internal' => count($commands['internal']),
        ];
        $counts['total'] = $counts['stable'] + $counts['experimental'] + $counts['internal'];

        if ($counts['total'] === 0) {
            return null;
        }

        return [
            'name' => $name,
            'summary' => $this->helpGroupSummary($name),
            'usage' => 'help ' . $name . ' [<command>]',
            'counts' => $counts,
            'commands' => $commands,
        ];
    }

    /**
     * @param array<string,mixed> $entry
     */
    private function commandBelongsToGroup(string $group, array $entry): bool
    {
        $signature = strtolower((string) ($entry['signature'] ?? ''));

        return $signature === $group
            || str_starts_with($signature, $group . ' ')
            || str_starts_with($signature, $group . ':');
    }

    private function helpGroupSummary(string $group): string
    {
        return match ($group) {
            'cache' => 'Inspect or clear deterministic compile cache state.',
            'compile' => 'Compile authored source-of-truth files into canonical graph artifacts.',
            'examples' => 'List or load curated onboarding examples with explicit taxonomy and copy mode.',
            'export' => 'Export graph and API artifacts for docs and tooling.',
            'generate' => 'Generate docs, scaffolds, helper artifacts, and framework-managed outputs.',
            'graph' => 'Inspect or render graph slices through the graph command family.',
            'init' => 'Run the deterministic first-run flow or load a curated onboarding example without prompts.',
            'inspect' => 'Inspect compiled graph, feature, integration, and reference surfaces.',
            'license' => 'Inspect or manage local license identity and service access state.',
            'observe' => 'Capture or compare graph-aware trace and profile summaries.',
            'pack' => 'Search hosted packs or install, inspect, and deactivate deterministic Foundry packs.',
            'queue' => 'Browse local development queue commands.',
            'schedule' => 'Browse local development scheduler commands.',
            'verify' => 'Verify graph, pipeline, contract, integration, and extension surfaces.',
            default => ucfirst($group) . ' command family.',
        };
    }

    /**
     * @param array<string,mixed> $group
     */
    private function renderHelpGroup(array $group): string
    {
        $lines = [
            'Command Family: ' . (string) ($group['name'] ?? ''),
            'Usage: ' . (string) ($group['usage'] ?? ''),
            'Summary: ' . (string) ($group['summary'] ?? ''),
            '',
        ];

        $commands = is_array($group['commands'] ?? null) ? $group['commands'] : [];
        foreach (['stable' => 'Stable', 'experimental' => 'Experimental', 'internal' => 'Internal'] as $key => $label) {
            $entries = is_array($commands[$key] ?? null) ? $commands[$key] : [];
            if ($entries === []) {
                continue;
            }

            $lines[] = $label . ' Commands:';
            foreach ($entries as $entry) {
                if (!is_array($entry)) {
                    continue;
                }

                $lines[] = '- ' . (string) ($entry['signature'] ?? '') . ': ' . (string) ($entry['summary'] ?? '');
            }
            $lines[] = '';
        }

        $lines[] = 'Use `foundry help <full command>` for exact usage, stability, and semver details.';

        return implode(PHP_EOL, $lines);
    }
}
