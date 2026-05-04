<?php

declare(strict_types=1);

namespace Foundry\Tests\Unit;

use Foundry\Notifications\NotificationDispatcher;
use Foundry\Notifications\NotificationRegistry;
use Foundry\Support\FoundryError;
use Foundry\Support\Paths;
use Foundry\Tests\Fixtures\TempProject;
use PHPUnit\Framework\TestCase;

final class NotificationRegistryAndDispatcherTest extends TestCase
{
    private TempProject $project;
    private string $cwd;

    protected function setUp(): void
    {
        $this->project = new TempProject();
        $this->cwd = getcwd() ?: '.';
        chdir($this->project->root);
    }

    protected function tearDown(): void
    {
        chdir($this->cwd);
        $this->project->cleanup();
    }

    public function test_registry_sorts_filters_and_serves_definitions_from_build_index(): void
    {
        $this->writeBuildIndex(<<<'PHP'
<?php
return [
    'z-last' => ['template_path' => 'templates/z.php', 'queue' => 'critical'],
    '' => ['template_path' => 'templates/skip.php'],
    'a-first' => ['template_path' => 'templates/a.php'],
    7 => ['template_path' => 'templates/skip.php'],
];
PHP);

        $registry = new NotificationRegistry(new Paths($this->project->root));
        $all = $registry->all();

        $this->assertSame(['a-first', 'z-last'], array_keys($all));
        $this->assertTrue($registry->has('a-first'));
        $this->assertFalse($registry->has('missing'));
        $this->assertSame('critical', $registry->get('z-last')['queue']);
    }

    public function test_registry_falls_back_to_generated_index_and_throws_for_invalid_payload(): void
    {
        $generatedPath = $this->project->root . '/app/generated/notification_index.php';
        file_put_contents($generatedPath, "<?php\nreturn 'invalid';\n");

        $registry = new NotificationRegistry(new Paths($this->project->root));

        $this->expectException(FoundryError::class);

        try {
            $registry->all();
        } catch (FoundryError $error) {
            $this->assertSame('NOTIFICATION_INDEX_INVALID', $error->errorCode);
            throw $error;
        }
    }

    public function test_dispatcher_handles_queue_and_sync_modes_and_validates_inputs(): void
    {
        $this->writeBuildIndex(<<<'PHP'
<?php
return [
    'welcome' => ['template_path' => 'templates/welcome.php', 'queue' => 'emails'],
];
PHP);
        $templateDir = $this->project->root . '/templates';
        if (!is_dir($templateDir)) {
            mkdir($templateDir, 0777, true);
        }
        file_put_contents($templateDir . '/welcome.php', <<<'PHP'
<?php
return [
    'subject' => 'Welcome {{name}}',
    'text' => 'Hello {{name}}',
    'html' => '<p>Hello {{name}}</p>',
];
PHP);

        $dispatcher = new NotificationDispatcher(new NotificationRegistry(new Paths($this->project->root)));

        $queued = $dispatcher->dispatch('welcome', ['name' => 'Ada'], 'queue');
        $this->assertSame('queued', $queued['status']);
        $this->assertSame('emails', $queued['queue']);

        $delivered = $dispatcher->dispatch('welcome', ['name' => 'Ada'], 'SYNC');
        $this->assertSame('delivered', $delivered['status']);
        $this->assertSame('Hello Ada', $delivered['rendered']['text']);

        $this->expectException(FoundryError::class);
        try {
            $dispatcher->dispatch('welcome', [], 'invalid-mode');
        } catch (FoundryError $error) {
            $this->assertSame('NOTIFICATION_MODE_INVALID', $error->errorCode);
            throw $error;
        }
    }

    public function test_dispatcher_requires_template_path_configuration(): void
    {
        $this->writeBuildIndex("<?php\nreturn ['welcome' => ['queue' => 'emails']];\n");
        $dispatcher = new NotificationDispatcher(new NotificationRegistry(new Paths($this->project->root)));

        $this->expectException(FoundryError::class);
        try {
            $dispatcher->dispatch('welcome', []);
        } catch (FoundryError $error) {
            $this->assertSame('NOTIFICATION_TEMPLATE_NOT_CONFIGURED', $error->errorCode);
            throw $error;
        }
    }

    public function test_registry_throws_when_notification_is_not_found(): void
    {
        $this->writeBuildIndex("<?php\nreturn ['welcome' => ['template_path' => 'templates/welcome.php']];\n");
        $registry = new NotificationRegistry(new Paths($this->project->root));

        $this->expectException(FoundryError::class);
        try {
            $registry->get('missing');
        } catch (FoundryError $error) {
            $this->assertSame('NOTIFICATION_NOT_FOUND', $error->errorCode);
            throw $error;
        }
    }

    private function writeBuildIndex(string $php): void
    {
        $directory = $this->project->root . '/app/.foundry/build/projections';
        if (!is_dir($directory)) {
            mkdir($directory, 0777, true);
        }
        file_put_contents($directory . '/notification_index.php', $php);
    }
}
