<?php
declare(strict_types=1);

namespace Foundry\CLI\Commands;

use Foundry\CLI\Command;
use Foundry\CLI\CommandContext;
use Foundry\Compiler\CompileOptions;

final class CompileGraphCommand extends Command
{
    #[\Override]
    public function matches(array $args): bool
    {
        return ($args[0] ?? null) === 'compile' && ($args[1] ?? null) === 'graph';
    }

    #[\Override]
    public function run(array $args, CommandContext $context): array
    {
        [$feature, $changedOnly, $useCache] = $this->parseOptions($args);

        $result = $context->graphCompiler()->compile(new CompileOptions(
            feature: $feature,
            changedOnly: $changedOnly,
            emit: true,
            useCache: $useCache,
        ));

        $summary = $result->diagnostics->summary();
        $status = ((int) ($summary['error'] ?? 0) > 0) ? 1 : 0;
        $cacheStatus = (string) ($result->cache['status'] ?? '');

        return [
            'status' => $status,
            'message' => $status === 0
                ? match ($cacheStatus) {
                    'hit' => 'Compile cache hit; reused existing build.',
                    'disabled' => 'Graph compiled without using the compile cache.',
                    'invalidated' => 'Graph compiled after cache invalidation.',
                    default => 'Graph compiled.',
                }
                : 'Graph compiled with errors.',
            'payload' => $result->toArray(),
        ];
    }

    /**
     * @param array<int,string> $args
     * @return array{0:string|null,1:bool,2:bool}
     */
    private function parseOptions(array $args): array
    {
        $feature = null;
        $changedOnly = false;
        $useCache = true;

        foreach ($args as $index => $arg) {
            if ($arg === '--changed-only') {
                $changedOnly = true;
                continue;
            }

            if ($arg === '--no-cache') {
                $useCache = false;
                continue;
            }

            if (str_starts_with($arg, '--feature=')) {
                $feature = substr($arg, strlen('--feature='));
                continue;
            }

            if ($arg === '--feature') {
                $value = (string) ($args[$index + 1] ?? '');
                if ($value !== '') {
                    $feature = $value;
                }
            }
        }

        if ($feature === '') {
            $feature = null;
        }

        return [$feature, $changedOnly, $useCache];
    }
}
