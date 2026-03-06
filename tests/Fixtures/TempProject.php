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
        mkdir($this->root . '/app/platform/migrations', 0777, true);
        mkdir($this->root . '/app/platform/logs', 0777, true);
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
