<?php

declare(strict_types=1);

namespace Foundry\Context;

use Foundry\Support\Paths;

final class ExecutionSpecValidationService
{
    /**
     * @var list<string>
     */
    private const IGNORED_ROOT_FILES = [
        'docs/specs/README.md',
        'docs/specs/implementation-log.md',
    ];

    public function __construct(
        private readonly Paths $paths,
    ) {}

    /**
     * @return array{
     *     ok:bool,
     *     summary:array{checked_files:int,features:int,violations:int},
     *     violations:list<array<string,mixed>>
     * }
     */
    public function validate(): array
    {
        $violations = [];
        $checkedFiles = 0;
        $features = [];
        $seenIds = [];

        foreach ($this->specFiles() as $relativePath) {
            if (in_array($relativePath, self::IGNORED_ROOT_FILES, true)) {
                continue;
            }

            $checkedFiles++;

            $placement = $this->classifyPlacement($relativePath);
            if ($placement === null) {
                $violations[] = $this->violation(
                    'EXECUTION_SPEC_INVALID_DIRECTORY',
                    $relativePath,
                    'Execution specs must live at docs/specs/<feature>/<id>-<slug>.md or docs/specs/<feature>/drafts/<id>-<slug>.md.',
                );

                continue;
            }

            $features[$placement['feature']] = true;

            $parsedName = ExecutionSpecFilename::parseName($placement['name']);
            if ($parsedName === null) {
                $violations[] = $this->violation(
                    'EXECUTION_SPEC_INVALID_FILENAME',
                    $relativePath,
                    'Execution spec filenames must use <id>-<slug>.md with one or more dot-separated 3-digit ID segments.',
                );

                continue;
            }

            $seenIds[$placement['feature']][$parsedName['id']][] = $relativePath;

            $contents = file_get_contents($this->paths->join($relativePath));
            if ($contents === false) {
                $violations[] = $this->violation(
                    'EXECUTION_SPEC_FILE_UNREADABLE',
                    $relativePath,
                    'Execution spec file could not be read.',
                );

                continue;
            }

            if ($this->firstLine($contents) !== ExecutionSpecFilename::heading($parsedName['name'])) {
                $violations[] = $this->violation(
                    'EXECUTION_SPEC_INVALID_HEADING',
                    $relativePath,
                    'Execution spec heading must mirror the filename only.',
                );
            }

            foreach ($this->metadataViolations($relativePath, $contents) as $metadataViolation) {
                $violations[] = $metadataViolation;
            }
        }

        foreach ($seenIds as $feature => $ids) {
            foreach ($ids as $id => $paths) {
                if (count($paths) < 2) {
                    continue;
                }

                sort($paths);
                $violations[] = $this->violation(
                    'EXECUTION_SPEC_DUPLICATE_ID',
                    $paths[0],
                    'Execution spec IDs must be unique within a feature.',
                    [
                        'feature' => $feature,
                        'id' => $id,
                        'paths' => $paths,
                    ],
                );
            }
        }

        usort($violations, static function (array $left, array $right): int {
            return strcmp(
                (string) (($left['file_path'] ?? '') . "\n" . ($left['code'] ?? '')),
                (string) (($right['file_path'] ?? '') . "\n" . ($right['code'] ?? '')),
            );
        });

        return [
            'ok' => $violations === [],
            'summary' => [
                'checked_files' => $checkedFiles,
                'features' => count($features),
                'violations' => count($violations),
            ],
            'violations' => $violations,
        ];
    }

    /**
     * @return list<string>
     */
    private function specFiles(): array
    {
        $root = $this->paths->join('docs/specs');
        if (!is_dir($root)) {
            return [];
        }

        $files = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
        );

        foreach ($iterator as $file) {
            if (!$file instanceof \SplFileInfo || !$file->isFile()) {
                continue;
            }

            if ($file->getExtension() !== 'md') {
                continue;
            }

            $relativePath = $this->relativePath($file->getPathname());
            if ($relativePath === null) {
                continue;
            }

            $files[] = $relativePath;
        }

        sort($files);

        return $files;
    }

    /**
     * @return array{feature:string,status:string,name:string}|null
     */
    private function classifyPlacement(string $relativePath): ?array
    {
        if (preg_match('#^docs/specs/(?<feature>[a-z0-9]+(?:-[a-z0-9]+)*)/(?<name>[^/]+)\.md$#', $relativePath, $matches) === 1) {
            return [
                'feature' => (string) $matches['feature'],
                'status' => 'active',
                'name' => (string) $matches['name'],
            ];
        }

        if (preg_match('#^docs/specs/(?<feature>[a-z0-9]+(?:-[a-z0-9]+)*)/drafts/(?<name>[^/]+)\.md$#', $relativePath, $matches) === 1) {
            return [
                'feature' => (string) $matches['feature'],
                'status' => 'draft',
                'name' => (string) $matches['name'],
            ];
        }

        return null;
    }

    private function relativePath(string $absolutePath): ?string
    {
        $root = rtrim($this->paths->root(), '/');
        if (!str_starts_with($absolutePath, $root . '/')) {
            return null;
        }

        return substr($absolutePath, strlen($root) + 1);
    }

    private function firstLine(string $contents): string
    {
        $firstLine = strtok(str_replace("\r\n", "\n", $contents), "\n");

        return $firstLine === false ? '' : trim($firstLine);
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function metadataViolations(string $relativePath, string $contents): array
    {
        $violations = [];
        $insideFence = false;

        foreach (preg_split('/\R/', $contents) ?: [] as $lineNumber => $line) {
            $trimmed = trim($line);

            if (str_starts_with($trimmed, '```')) {
                $insideFence = !$insideFence;

                continue;
            }

            if ($insideFence) {
                continue;
            }

            if (preg_match('/^(?:[-*]\s+)?(?<field>id|parent|status)\s*:/i', $trimmed, $matches) !== 1) {
                continue;
            }

            $field = strtolower((string) $matches['field']);
            $violations[] = $this->violation(
                'EXECUTION_SPEC_FORBIDDEN_METADATA',
                $relativePath,
                'Execution specs must not define `' . $field . '` metadata inside the file.',
                [
                    'field' => $field,
                    'line' => $lineNumber + 1,
                ],
            );
        }

        return $violations;
    }

    /**
     * @param array<string,mixed> $details
     * @return array<string,mixed>
     */
    private function violation(string $code, string $filePath, string $message, array $details = []): array
    {
        return [
            'code' => $code,
            'message' => $message,
            'file_path' => $filePath,
            'details' => $details,
        ];
    }
}
