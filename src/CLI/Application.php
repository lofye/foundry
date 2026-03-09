<?php
declare(strict_types=1);

namespace Foundry\CLI;

use Foundry\CLI\Commands\GenerateFeatureCommand;
use Foundry\CLI\Commands\GenerateIndexesCommand;
use Foundry\CLI\Commands\GeneratePhaseOneCommand;
use Foundry\CLI\Commands\GeneratePhaseThreeCommand;
use Foundry\CLI\Commands\GeneratePhaseTwoCommand;
use Foundry\CLI\Commands\GraphVisualizeCommand;
use Foundry\CLI\Commands\ImpactCommand;
use Foundry\CLI\Commands\InitAppCommand;
use Foundry\CLI\Commands\InspectApiCommand;
use Foundry\CLI\Commands\InspectGraphCommand;
use Foundry\CLI\Commands\InspectPhaseThreeCommand;
use Foundry\CLI\Commands\InspectFeatureCommand;
use Foundry\CLI\Commands\InspectNotificationCommand;
use Foundry\CLI\Commands\InspectResourceCommand;
use Foundry\CLI\Commands\InspectRouteCommand;
use Foundry\CLI\Commands\MigrateSpecsCommand;
use Foundry\CLI\Commands\DoctorCommand;
use Foundry\CLI\Commands\ExportOpenApiCommand;
use Foundry\CLI\Commands\PreviewNotificationCommand;
use Foundry\CLI\Commands\PromptCommand;
use Foundry\CLI\Commands\QueueWorkCommand;
use Foundry\CLI\Commands\ScheduleRunCommand;
use Foundry\CLI\Commands\ServeCommand;
use Foundry\CLI\Commands\CodemodRunCommand;
use Foundry\CLI\Commands\CompileGraphCommand;
use Foundry\CLI\Commands\VerifyCompatibilityCommand;
use Foundry\CLI\Commands\VerifyGraphCommand;
use Foundry\CLI\Commands\VerifyPipelineCommand;
use Foundry\CLI\Commands\VerifyContractsCommand;
use Foundry\CLI\Commands\VerifyFeatureCommand;
use Foundry\CLI\Commands\VerifyPhaseTwoCommand;
use Foundry\CLI\Commands\VerifyPhaseThreeCommand;
use Foundry\CLI\Commands\VerifyResourceCommand;
use Foundry\Support\FoundryError;
use Foundry\Support\Json;

final class Application
{
    /**
     * @var array<int,Command>
     */
    private array $commands;

    public function __construct(?array $commands = null)
    {
        $this->commands = $commands ?? [
            new CompileGraphCommand(),
            new InspectGraphCommand(),
            new DoctorCommand(),
            new GraphVisualizeCommand(),
            new PromptCommand(),
            new VerifyGraphCommand(),
            new VerifyPipelineCommand(),
            new VerifyCompatibilityCommand(),
            new MigrateSpecsCommand(),
            new CodemodRunCommand(),
            new InspectFeatureCommand(),
            new InspectNotificationCommand(),
            new InspectApiCommand(),
            new InspectResourceCommand(),
            new InspectPhaseThreeCommand(),
            new InspectRouteCommand(),
            new InitAppCommand(),
            new GeneratePhaseOneCommand(),
            new GeneratePhaseTwoCommand(),
            new GeneratePhaseThreeCommand(),
            new GenerateFeatureCommand(),
            new GenerateIndexesCommand(),
            new ExportOpenApiCommand(),
            new PreviewNotificationCommand(),
            new VerifyFeatureCommand(),
            new VerifyResourceCommand(),
            new VerifyPhaseTwoCommand(),
            new VerifyPhaseThreeCommand(),
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

        $context = new CommandContext();

        try {
            $command = array_find(
                $this->commands,
                static fn (Command $candidate): bool => $candidate->matches($args),
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

        if ($result['payload'] !== null) {
            echo Json::encode($result['payload'], true) . PHP_EOL;
        }

        return $result['status'];
    }
}
