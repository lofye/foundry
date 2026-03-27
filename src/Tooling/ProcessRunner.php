<?php

declare(strict_types=1);

namespace Foundry\Tooling;

final class ProcessRunner
{
    /**
     * @param list<string> $command
     * @return array{ok:bool,exit_code:int,stdout:string,stderr:string,command:list<string>}
     */
    public function run(array $command, string $cwd): array
    {
        if ($command === []) {
            return [
                'ok' => false,
                'exit_code' => 1,
                'stdout' => '',
                'stderr' => 'Empty command.',
                'command' => [],
            ];
        }

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = proc_open($command, $descriptors, $pipes, $cwd);
        if (!is_resource($process)) {
            return [
                'ok' => false,
                'exit_code' => 1,
                'stdout' => '',
                'stderr' => 'Process could not be started.',
                'command' => $command,
            ];
        }

        fclose($pipes[0]);

        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);

        fclose($pipes[1]);
        fclose($pipes[2]);

        $exitCode = proc_close($process);

        return [
            'ok' => $exitCode === 0,
            'exit_code' => $exitCode,
            'stdout' => is_string($stdout) ? $stdout : '',
            'stderr' => is_string($stderr) ? $stderr : '',
            'command' => $command,
        ];
    }
}
