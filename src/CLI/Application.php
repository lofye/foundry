<?php
declare(strict_types=1);

namespace Forge\CLI;

use Forge\CLI\Commands\GenerateFeatureCommand;
use Forge\CLI\Commands\GenerateIndexesCommand;
use Forge\CLI\Commands\ImpactCommand;
use Forge\CLI\Commands\InspectFeatureCommand;
use Forge\CLI\Commands\InspectRouteCommand;
use Forge\CLI\Commands\QueueWorkCommand;
use Forge\CLI\Commands\ScheduleRunCommand;
use Forge\CLI\Commands\ServeCommand;
use Forge\CLI\Commands\VerifyContractsCommand;
use Forge\CLI\Commands\VerifyFeatureCommand;
use Forge\Support\ForgeError;
use Forge\Support\Json;

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
            foreach ($this->commands as $command) {
                if (!$command->matches($args)) {
                    continue;
                }

                $result = $command->run($args, $context);
                return $this->emitResult($result, $json);
            }

            throw new ForgeError('CLI_COMMAND_NOT_FOUND', 'not_found', ['args' => $args], 'Command not found.');
        } catch (ForgeError $error) {
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
