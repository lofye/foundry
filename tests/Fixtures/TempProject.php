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
        file_put_contents($this->root . '/vendor/bin/phpunit', <<<'PHP'
#!/usr/bin/env php
<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$args = $_SERVER['argv'] ?? [];
$isCoverage = in_array('--coverage-text', $args, true);
$coverageCloverPath = null;

foreach ($args as $index => $arg) {
    if ($arg !== '--coverage-clover') {
        continue;
    }

    $coverageCloverPath = $args[$index + 1] ?? null;
    break;
}

$readControl = static function (string $name, string $default) use ($root): string {
    $path = $root . '/' . $name;
    if (!is_file($path)) {
        return $default;
    }

    return rtrim((string) file_get_contents($path), "\r\n");
};

if ($isCoverage) {
    $exitCode = (int) $readControl('.foundry-test-coverage-exit-code', '0');
    $skipCloverWrite = trim($readControl('.foundry-test-skip-coverage-clover', '')) === '1';
    $defaultOutput = sprintf(
        "PHPUnit 12.0.0 by Sebastian Bergmann and contributors.\n\nCode Coverage Report:\n  2026-04-21 12:00:00\n\nSummary:\n  Classes: 100.00%% (10/10)\n  Methods: 100.00%% (20/20)\n  Lines:   %s%% (95/100)\n",
        $readControl('.foundry-test-coverage-lines', '95.00'),
    );

    if (!$skipCloverWrite && is_string($coverageCloverPath) && $coverageCloverPath !== '') {
        $coverageFiles = json_decode($readControl('.foundry-test-coverage-files.json', '[]'), true);
        if (!is_array($coverageFiles)) {
            $coverageFiles = [];
        }

        if ($coverageFiles === []) {
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
            );

            foreach ($iterator as $file) {
                if (!$file instanceof SplFileInfo || !$file->isFile()) {
                    continue;
                }

                $absolutePath = str_replace('\\', '/', $file->getPathname());
                $relativePath = ltrim(str_replace('\\', '/', substr($absolutePath, strlen($root))), '/');
                if (!str_ends_with($relativePath, '.php')) {
                    continue;
                }

                if (str_starts_with($relativePath, 'vendor/')) {
                    continue;
                }

                $coverageFiles[] = [
                    'path' => $absolutePath,
                    'statements' => 10,
                    'covered_statements' => 10,
                ];
            }
        }

        $xml = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<coverage generated=\"0\">\n  <project timestamp=\"0\">\n";
        foreach ($coverageFiles as $row) {
            if (!is_array($row) || !is_string($row['path'] ?? null)) {
                continue;
            }

            $statements = (int) ($row['statements'] ?? 0);
            $coveredStatements = (int) ($row['covered_statements'] ?? 0);
            $xml .= sprintf(
                "    <file name=\"%s\">\n      <metrics statements=\"%d\" coveredstatements=\"%d\"/>\n    </file>\n",
                htmlspecialchars((string) $row['path'], ENT_QUOTES),
                $statements,
                $coveredStatements,
            );
        }
        $xml .= "  </project>\n</coverage>\n";

        $coverageDir = dirname($coverageCloverPath);
        if (!is_dir($coverageDir)) {
            mkdir($coverageDir, 0777, true);
        }

        file_put_contents($coverageCloverPath, $xml);
    }

    fwrite(STDOUT, $readControl('.foundry-test-coverage-output', $defaultOutput));
    exit($exitCode);
}

$exitCode = (int) $readControl('.foundry-test-phpunit-exit-code', '0');
$output = $readControl(
    '.foundry-test-phpunit-output',
    $exitCode === 0 ? "PHPUnit passed.\n" : "PHPUnit failed.\n",
);
fwrite($exitCode === 0 ? STDOUT : STDERR, $output);
exit($exitCode);
PHP);
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
