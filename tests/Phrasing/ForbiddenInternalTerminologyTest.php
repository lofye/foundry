<?php
declare(strict_types=1);

namespace Foundry\Tests\Phrasing;

use PHPUnit\Framework\TestCase;
use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

final class ForbiddenInternalTerminologyTest extends TestCase
{
    public function test_repo_does_not_contain_internal_phase_or_spec_terms(): void
    {
        $root = dirname(__DIR__, 2);

        $forbidden = [
            'Phase0',
            'Phase1',
            'Phase2',
            'Phase3',
            'Phase4',
            'Phase 0',
            'Phase 1',
            'Phase 2',
            'Phase 3',
            'Phase 4',
            'Spec0',
            'Spec1',
            'Spec2',
            'Spec3',
            'Spec4',
            'Spec 0',
            'Spec 1',
            'Spec 2',
            'Spec 3',
            'Spec 4',
            'PhaseTwo',
            'GeneratePhase',
            'CliPhase',
        ];

        $extensions = ['php', 'md', 'json', 'js', 'html', 'yml', 'yaml', 'txt'];

        $skipDirs = [
            DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR,
            DIRECTORY_SEPARATOR . 'node_modules' . DIRECTORY_SEPARATOR,
            DIRECTORY_SEPARATOR . '.git' . DIRECTORY_SEPARATOR,
            DIRECTORY_SEPARATOR . 'content' . DIRECTORY_SEPARATOR . 'docs' . DIRECTORY_SEPARATOR . 'generated' . DIRECTORY_SEPARATOR,
            DIRECTORY_SEPARATOR . 'docs-build' . DIRECTORY_SEPARATOR,
        ];

        $skipFiles = [
            'ForbiddenInternalTerminologyTest.php',
        ];

        $violations = [];

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)
        );

        /** @var SplFileInfo $file */
        foreach ($iterator as $file) {
            if (!$file->isFile()) {
                continue;
            }

            $path = $file->getPathname();

            foreach ($skipDirs as $skipDir) {
                if (str_contains($path, $skipDir)) {
                    continue 2;
                }
            }

            if (in_array($file->getFilename(), $skipFiles, true)) {
                continue;
            }

            $extension = strtolower($file->getExtension());
            if (!in_array($extension, $extensions, true)) {
                continue;
            }

            $contents = file_get_contents($path);
            if (!is_string($contents) || $contents === '') {
                continue;
            }

            foreach ($forbidden as $term) {
                if (str_contains($contents, $term)) {
                    $violations[] = sprintf(
                        '%s contains forbidden term "%s"',
                        $this->relativePath($path, $root),
                        $term
                    );
                }
            }
        }

        if ($violations !== []) {
            sort($violations);

            $this->fail(
                "Forbidden internal terminology found:\n\n" .
                implode("\n", $violations) .
                "\n\nUse feature or architecture names instead."
            );
        }

        $this->assertTrue(true);
    }

    private function relativePath(string $path, string $root): string
    {
        $normalizedRoot = rtrim(str_replace('\\', '/', $root), '/');
        $normalizedPath = str_replace('\\', '/', $path);

        if (str_starts_with($normalizedPath, $normalizedRoot . '/')) {
            return substr($normalizedPath, strlen($normalizedRoot) + 1);
        }

        return ltrim($normalizedPath, '/');
    }
}
