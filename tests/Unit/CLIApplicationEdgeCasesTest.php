<?php
declare(strict_types=1);

namespace Foundry\Tests\Unit;

use Foundry\CLI\Application;
use Foundry\CLI\Command;
use Foundry\CLI\CommandContext;
use PHPUnit\Framework\TestCase;

final class CLIApplicationEdgeCasesTest extends TestCase
{
    public function test_unhandled_exception_is_returned_as_structured_error(): void
    {
        $app = new Application([
            new class extends Command {
                #[\Override]
                public function matches(array $args): bool
                {
                    return true;
                }

                #[\Override]
                public function run(array $args, CommandContext $context): array
                {
                    throw new \RuntimeException('boom');
                }
            },
        ]);

        ob_start();
        $status = $app->run(['foundry', 'anything', '--json']);
        $output = ob_get_clean() ?: '';

        /** @var array<string,mixed> $payload */
        $payload = json_decode($output, true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame(1, $status);
        $this->assertSame('CLI_UNHANDLED_EXCEPTION', $payload['error']['code']);
    }

    public function test_plain_output_mode_handles_null_payload_and_message(): void
    {
        $app = new Application([
            new class extends Command {
                #[\Override]
                public function matches(array $args): bool
                {
                    return true;
                }

                #[\Override]
                public function run(array $args, CommandContext $context): array
                {
                    return ['status' => 0, 'payload' => null, 'message' => null];
                }
            },
        ]);

        ob_start();
        $status = $app->run(['foundry', 'anything']);
        $output = ob_get_clean() ?: '';

        $this->assertSame(0, $status);
        $this->assertSame('', $output);
    }
}
