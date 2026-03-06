<?php
declare(strict_types=1);

namespace Foundry\CLI;

use Foundry\CLI\Commands\GenerateFeatureCommand;
use Foundry\CLI\Commands\GenerateIndexesCommand;
use Foundry\CLI\Commands\ImpactCommand;
use Foundry\CLI\Commands\InitAppCommand;
use Foundry\CLI\Commands\InspectFeatureCommand;
use Foundry\CLI\Commands\InspectRouteCommand;
use Foundry\CLI\Commands\QueueWorkCommand;
use Foundry\CLI\Commands\ScheduleRunCommand;
use Foundry\CLI\Commands\ServeCommand;
use Foundry\CLI\Commands\VerifyContractsCommand;
use Foundry\CLI\Commands\VerifyFeatureCommand;
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
            new InspectFeatureCommand(),
            new InspectRouteCommand(),
            new InitAppCommand(),
            new GenerateFeatureCommand(),
            new GenerateIndexesCommand(),
            new VerifyFeatureCommand(),
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
