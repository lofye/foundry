<?php

declare(strict_types=1);

namespace Foundry\Tests\Integration;

use Foundry\CLI\Application;
use Foundry\CLI\Commands\EventCommand;
use Foundry\Event\EventRegistry;
use PHPUnit\Framework\TestCase;

final class CLIEventCommandTest extends TestCase
{
    public function test_event_list_and_inspect_json_are_deterministic(): void
    {
        $registry = new EventRegistry();
        $registry->register('feature.created', static function (array $payload): void {}, priority: 10, source: 'pack:alpha');
        $registry->register('feature.created', static function (array $payload): void {}, priority: 0, source: 'pack:beta');
        $registry->register('feature.updated', static function (array $payload): void {}, priority: 0, source: 'pack:alpha');

        $app = new Application([new EventCommand($registry)]);

        $list = $this->runCommand($app, ['foundry', 'event:list', '--json']);
        $this->assertSame(0, $list['status']);
        $this->assertSame([
            ['name' => 'feature.created', 'listener_count' => 2],
            ['name' => 'feature.updated', 'listener_count' => 1],
        ], $list['payload']['events']);

        $inspect = $this->runCommand($app, ['foundry', 'event:inspect', 'feature.created', '--json']);
        $this->assertSame(0, $inspect['status']);
        $this->assertSame('feature.created', $inspect['payload']['event']);
        $this->assertSame(10, $inspect['payload']['listeners'][0]['priority']);
        $this->assertSame('pack:alpha', $inspect['payload']['listeners'][0]['source']);
    }

    public function test_event_inspect_missing_event_is_non_fatal(): void
    {
        $app = new Application([new EventCommand(new EventRegistry())]);
        $result = $this->runCommand($app, ['foundry', 'event:inspect', 'missing.event', '--json']);

        $this->assertSame(0, $result['status']);
        $this->assertSame('missing.event', $result['payload']['event']);
        $this->assertSame([], $result['payload']['listeners']);
    }

    /**
     * @param array<int,string> $argv
     * @return array{status:int,payload:array<string,mixed>}
     */
    private function runCommand(Application $app, array $argv): array
    {
        ob_start();
        $status = $app->run($argv);
        $output = trim((string) ob_get_clean());

        /** @var array<string,mixed> $payload */
        $payload = json_decode($output, true, 512, JSON_THROW_ON_ERROR);

        return ['status' => $status, 'payload' => $payload];
    }
}
