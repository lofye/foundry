<?php
declare(strict_types=1);

namespace Foundry\Tests\Fixtures;

final class TempProject
{
    public readonly string $root;

    public function __construct()
    {
        $base = sys_get_temp_dir() . '/foundry-tests-' . bin2hex(random_bytes(6));
        mkdir($base, 0777, true);
        $this->root = $base;

        mkdir($this->root . '/app/features', 0777, true);
        mkdir($this->root . '/app/generated', 0777, true);
        mkdir($this->root . '/app/.foundry/build', 0777, true);
        mkdir($this->root . '/database/migrations', 0777, true);
        mkdir($this->root . '/storage/files', 0777, true);
        mkdir($this->root . '/storage/logs', 0777, true);
        mkdir($this->root . '/storage/tmp', 0777, true);
        mkdir($this->root . '/vendor/bin', 0777, true);

        file_put_contents($this->root . '/composer.json', <<<'JSON'
{
  "name": "foundry/tests-app",
  "type": "project",
  "require": {
    "php": "^8.4",
    "ext-json": "*",
    "ext-pdo": "*"
  }
}
JSON);
        file_put_contents($this->root . '/vendor/autoload.php', "<?php\n");
        file_put_contents($this->root . '/vendor/bin/foundry', "#!/usr/bin/env php\n<?php\n");
        file_put_contents($this->root . '/foundry', <<<'PHP'
#!/usr/bin/env php
<?php
declare(strict_types=1);

$binary = __DIR__ . '/vendor/bin/foundry';
if (!is_file($binary)) {
    fwrite(STDERR, "Foundry dependencies are not installed. Missing vendor/bin/foundry. Run composer install first.\n");
    exit(1);
}

require $binary;
PHP);
    }

    public function cleanup(): void
    {
        $this->deleteDirectory($this->root);
    }

    private function deleteDirectory(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        $items = scandir($path);
        if ($items === false) {
            return;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $fullPath = $path . '/' . $item;
            if (is_dir($fullPath)) {
                $this->deleteDirectory($fullPath);
            } else {
                @unlink($fullPath);
            }
        }

        @rmdir($path);
    }
}
