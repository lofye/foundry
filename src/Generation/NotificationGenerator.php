<?php
declare(strict_types=1);

namespace Foundry\Generation;

use Foundry\Support\FoundryError;
use Foundry\Support\Paths;
use Foundry\Support\Str;
use Foundry\Support\Yaml;

final class NotificationGenerator
{
    public function __construct(private readonly Paths $paths)
    {
    }

    /**
     * @return array<string,mixed>
     */
    public function generate(string $name, bool $force = false): array
    {
        $notification = Str::toSnakeCase($name);
        if ($notification === '') {
            throw new FoundryError('NOTIFICATION_NAME_INVALID', 'validation', ['name' => $name], 'Notification name is invalid.');
        }

        $specDir = $this->paths->join('app/specs/notifications');
        $schemaDir = $this->paths->join('app/notifications/schemas');
        $templateDir = $this->paths->join('app/notifications/templates');
        $testsDir = $this->paths->join('app/notifications/tests');

        foreach ([$specDir, $schemaDir, $templateDir, $testsDir] as $dir) {
            if (!is_dir($dir)) {
                mkdir($dir, 0777, true);
            }
        }

        $specPath = $specDir . '/' . $notification . '.notification.yaml';
        if (is_file($specPath) && !$force) {
            throw new FoundryError('NOTIFICATION_SPEC_EXISTS', 'io', ['path' => $specPath], 'Notification spec already exists. Use --force to overwrite.');
        }

        $schemaPath = $schemaDir . '/' . $notification . '.input.schema.json';
        $templatePath = $templateDir . '/' . $notification . '.mail.php';
        $dispatchPath = $this->paths->join('app/notifications/dispatch_' . $notification . '.php');
        $testPath = $testsDir . '/' . $notification . '_notification_test.php';

        $spec = [
            'version' => 1,
            'notification' => $notification,
            'channel' => 'mail',
            'queue' => 'default',
            'template' => $notification,
            'input_schema' => 'app/notifications/schemas/' . $notification . '.input.schema.json',
            'dispatch_features' => [],
        ];

        file_put_contents($specPath, Yaml::dump($spec));

        file_put_contents($schemaPath, <<<'JSON'
{
  "$schema": "https://json-schema.org/draft/2020-12/schema",
  "type": "object",
  "additionalProperties": false,
  "required": ["user_id"],
  "properties": {
    "user_id": {"type": "string"},
    "email": {"type": "string", "format": "email"}
  }
}
JSON);

        file_put_contents($templatePath, $this->templateStub($notification));
        file_put_contents($dispatchPath, $this->dispatchStub($notification));
        file_put_contents($testPath, $this->testStub($notification));

        return [
            'notification' => $notification,
            'channel' => 'mail',
            'queue' => 'default',
            'files' => [$specPath, $schemaPath, $templatePath, $dispatchPath, $testPath],
            'spec' => $specPath,
        ];
    }

    private function templateStub(string $notification): string
    {
        return <<<PHP
<?php
declare(strict_types=1);

return [
    'subject' => 'Notification: {$notification}',
    'text' => "Hello {{user_id}},\nThis is the {$notification} notification.",
    'html' => '<p>Hello {{user_id}},</p><p>This is the {$notification} notification.</p>',
];
PHP;
    }

    private function dispatchStub(string $notification): string
    {
        $template = <<<'PHP'
<?php
declare(strict_types=1);

/**
 * Dispatch helper for notification {{NOTIFICATION}}.
 * Wire this into a feature action or event subscriber.
 */
return static function (array $input): array {
    return [
        'notification' => '{{NOTIFICATION}}',
        'queue' => 'default',
        'input' => $input,
    ];
};
PHP;

        return str_replace('{{NOTIFICATION}}', $notification, $template);
    }

    private function testStub(string $notification): string
    {
        $class = str_replace(' ', '', ucwords(str_replace('_', ' ', $notification))) . 'NotificationTest';

        return <<<PHP
<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class {$class} extends TestCase
{
    public function test_notification_template_renders_expected_keys(): void
    {
        self::assertTrue(true);
    }
}
PHP;
    }
}
