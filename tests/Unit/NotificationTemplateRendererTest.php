<?php
declare(strict_types=1);

namespace Foundry\Tests\Unit;

use Foundry\Notifications\NotificationTemplateRenderer;
use Foundry\Support\FoundryError;
use Foundry\Tests\Fixtures\TempProject;
use PHPUnit\Framework\TestCase;

final class NotificationTemplateRendererTest extends TestCase
{
    private TempProject $project;

    protected function setUp(): void
    {
        $this->project = new TempProject();
        mkdir($this->project->root . '/app/notifications/templates', 0777, true);
        file_put_contents($this->project->root . '/app/notifications/templates/welcome.mail.php', <<<'PHP'
<?php
declare(strict_types=1);

return [
    'subject' => 'Welcome {{user_id}}',
    'text' => 'Hello {{user_id}}',
    'html' => '<p>Hello {{user_id}}</p>',
];
PHP);
    }

    protected function tearDown(): void
    {
        $this->project->cleanup();
    }

    public function test_renders_php_template_with_variable_binding(): void
    {
        $renderer = new NotificationTemplateRenderer();
        $rendered = $renderer->render($this->project->root . '/app/notifications/templates/welcome.mail.php', ['user_id' => 'u_123']);

        $this->assertSame('Welcome u_123', $rendered['subject']);
        $this->assertSame('Hello u_123', $rendered['text']);
        $this->assertSame('<p>Hello u_123</p>', $rendered['html']);
    }

    public function test_renders_plain_text_template_and_normalizes_bindings(): void
    {
        file_put_contents(
            $this->project->root . '/app/notifications/templates/digest.mail.txt',
            'Name={{name}};Bool={{enabled}};Null={{optional}};Array={{meta}};Loop={{loop}};'
        );

        $loop = [];
        $loop['self'] = &$loop;

        $renderer = new NotificationTemplateRenderer();
        $rendered = $renderer->render(
            $this->project->root . '/app/notifications/templates/digest.mail.txt',
            [
                0 => 'ignored',
                '' => 'ignored',
                'name' => 'Ada',
                'enabled' => true,
                'optional' => null,
                'meta' => ['team' => 'foundry'],
                'loop' => $loop,
            ],
        );

        $this->assertSame('', $rendered['subject']);
        $this->assertSame('', $rendered['html']);
        $this->assertSame(
            'Name=Ada;Bool=true;Null=;Array={"team":"foundry"};Loop=;',
            $rendered['text'],
        );
    }

    public function test_render_throws_when_template_file_is_missing(): void
    {
        $renderer = new NotificationTemplateRenderer();

        try {
            $renderer->render($this->project->root . '/app/notifications/templates/missing.mail.php', []);
            self::fail('Expected template missing error.');
        } catch (FoundryError $error) {
            $this->assertSame('NOTIFICATION_TEMPLATE_NOT_FOUND', $error->errorCode);
        }
    }

    public function test_render_throws_when_php_template_does_not_return_array(): void
    {
        file_put_contents(
            $this->project->root . '/app/notifications/templates/invalid.mail.php',
            "<?php\ndeclare(strict_types=1);\n\nreturn 'invalid';\n",
        );

        $renderer = new NotificationTemplateRenderer();

        try {
            $renderer->render($this->project->root . '/app/notifications/templates/invalid.mail.php', []);
            self::fail('Expected invalid template error.');
        } catch (FoundryError $error) {
            $this->assertSame('NOTIFICATION_TEMPLATE_INVALID', $error->errorCode);
        }
    }
}
