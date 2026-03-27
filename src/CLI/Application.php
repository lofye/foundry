<?php

declare(strict_types=1);

namespace Foundry\CLI;

use Foundry\CLI\Commands\CacheClearCommand;
use Foundry\CLI\Commands\CacheInspectCommand;
use Foundry\CLI\Commands\CodemodRunCommand;
use Foundry\CLI\Commands\CompileGraphCommand;
use Foundry\CLI\Commands\DoctorCommand;
use Foundry\CLI\Commands\ExportGraphCommand;
use Foundry\CLI\Commands\ExportOpenApiCommand;
use Foundry\CLI\Commands\GenerateFeatureCommand;
use Foundry\CLI\Commands\GenerateIndexesCommand;
use Foundry\CLI\Commands\GenerateIntegrationCommand;
use Foundry\CLI\Commands\GeneratePlatformCommand;
use Foundry\CLI\Commands\GenerateScaffoldCommand;
use Foundry\CLI\Commands\GraphVisualizeCommand;
use Foundry\CLI\Commands\HistoryCommand;
use Foundry\CLI\Commands\ImpactCommand;
use Foundry\CLI\Commands\InitAppCommand;
use Foundry\CLI\Commands\InspectApiCommand;
use Foundry\CLI\Commands\InspectFeatureCommand;
use Foundry\CLI\Commands\InspectGraphCommand;
use Foundry\CLI\Commands\InspectNotificationCommand;
use Foundry\CLI\Commands\InspectPlatformCommand;
use Foundry\CLI\Commands\InspectResourceCommand;
use Foundry\CLI\Commands\InspectRouteCommand;
use Foundry\CLI\Commands\MigrateDefinitionsCommand;
use Foundry\CLI\Commands\ObserveCommand;
use Foundry\CLI\Commands\PreviewNotificationCommand;
use Foundry\CLI\Commands\PromptCommand;
use Foundry\CLI\Commands\QueueWorkCommand;
use Foundry\CLI\Commands\RegressionsCommand;
use Foundry\CLI\Commands\ScheduleRunCommand;
use Foundry\CLI\Commands\ServeCommand;
use Foundry\CLI\Commands\UpgradeCheckCommand;
use Foundry\CLI\Commands\VerifyCompatibilityCommand;
use Foundry\CLI\Commands\VerifyContractsCommand;
use Foundry\CLI\Commands\VerifyFeatureCommand;
use Foundry\CLI\Commands\VerifyGraphCommand;
use Foundry\CLI\Commands\VerifyIntegrationCommand;
use Foundry\CLI\Commands\VerifyPipelineCommand;
use Foundry\CLI\Commands\VerifyPlatformCommand;
use Foundry\CLI\Commands\VerifyResourceCommand;
use Foundry\Pro\CLI\DiffCommand;
use Foundry\Pro\CLI\ExplainCommand;
use Foundry\Pro\CLI\GenerateCommand as ProGenerateCommand;
use Foundry\Pro\CLI\ProCommand;
use Foundry\Pro\CLI\TraceCommand;
use Foundry\Support\ApiSurfaceRegistry;
use Foundry\Support\FoundryError;
use Foundry\Support\Json;

final class Application
{
    /**
     * @var array<int,Command>
     */
    private array $commands;
    private ApiSurfaceRegistry $apiSurfaceRegistry;

    public function __construct(?array $commands = null)
    {
        $this->commands = $commands ?? self::registeredCommands();
        $this->apiSurfaceRegistry = new ApiSurfaceRegistry();
    }

    /**
     * @return list<Command>
     */
    public static function registeredCommands(): array
    {
        return [
            new CompileGraphCommand(),
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
            new UpgradeCheckCommand(),
            new MigrateDefinitionsCommand(),
            new CodemodRunCommand(),
            new InspectFeatureCommand(),
            new InspectNotificationCommand(),
            new InspectApiCommand(),
            new InspectResourceCommand(),
            new InspectPlatformCommand(),
            new InspectRouteCommand(),
            new InitAppCommand(),
            new ProCommand(),
            new ProGenerateCommand(),
            new GenerateScaffoldCommand(),
            new GenerateIntegrationCommand(),
            new GeneratePlatformCommand(),
            new GenerateFeatureCommand(),
            new GenerateIndexesCommand(),
            new ExportOpenApiCommand(),
            new PreviewNotificationCommand(),
            new VerifyFeatureCommand(),
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
            if ($args === [] || ($args[0] ?? null) === 'help') {
                $helpArgs = ($args[0] ?? null) === 'help' ? array_slice($args, 1) : [];

                return $this->emitResult($this->helpResult($helpArgs, $json), $json);
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
        } catch (FoundryError $error) {
            return $this->emitResult(['status' => 1, 'payload' => $error->toArray(), 'message' => $error->getMessage()], $json);
        } catch (\Throwable $error) {
            $payload = [
                'error' => [
                    'code' => 'CLI_UNHANDLED_EXCEPTION',
                    'category' => 'runtime',
                    'message' => $error->getMessage(),
                    'details' => ['exception' => $error::class],
                ],
            ];

            return $this->emitResult(['status' => 1, 'payload' => $payload, 'message' => $error->getMessage()], $json);
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
        if ($command === null) {
            throw new FoundryError('CLI_HELP_COMMAND_NOT_FOUND', 'not_found', ['args' => $args], 'Help target not found.');
        }

        $payload = ['command' => $command];

        return [
            'status' => 0,
            'message' => $json ? null : $this->renderCommandHelp($command),
            'payload' => $json ? $payload : null,
        ];
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

                $availability = (string) ($entry['availability'] ?? 'core');
                $suffix = $availability === 'pro' ? ' [Pro]' : '';

                $lines[] = '- ' . (string) ($entry['signature'] ?? '') . $suffix . ': ' . (string) ($entry['summary'] ?? '');
            }
            $lines[] = '';
        }

        $lines[] = 'Use `foundry help <command>` for usage, stability, and semver details.';

        return implode(PHP_EOL, $lines);
    }

    /**
     * @param array<string,mixed> $command
     */
    private function renderCommandHelp(array $command): string
    {
        $lines = [
            'Command: ' . (string) ($command['signature'] ?? ''),
            'Usage: ' . (string) ($command['usage'] ?? ''),
            'Stability: ' . (string) ($command['stability'] ?? 'internal'),
            'Availability: ' . (((string) ($command['availability'] ?? 'core')) === 'pro' ? 'Foundry Pro' : 'Core'),
            'Classification: ' . (string) ($command['classification'] ?? 'internal_api'),
            'Summary: ' . (string) ($command['summary'] ?? ''),
            'Semver: ' . (string) ($command['semver_policy'] ?? ''),
        ];

        return implode(PHP_EOL, $lines);
    }
}
