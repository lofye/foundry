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
        'docs/features/README.md',
        'docs/features/implementation-log.md',
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
    public function validate(bool $requirePlans = false): array
    {
        $violations = [];
        $checkedFiles = 0;
        $features = [];
        $seenIds = [];
        $continuityCandidates = [];
        $activeSpecReferences = [];
        $activeSpecNames = [];
        $activeSpecPathsByFeature = [];

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
                    'Execution specs must live at docs/features/<feature>/specs/<id>-<slug>.md or docs/features/<feature>/specs/drafts/<id>-<slug>.md.',
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
            $location = $placement['status'] === 'draft' ? 'drafts' : 'active';
            $continuityCandidates[$placement['feature']][$location][] = [
                'id' => $parsedName['id'],
                'segments' => $parsedName['segments'],
                'path' => $relativePath,
            ];

            $contents = file_get_contents($this->paths->join($relativePath));
            if ($contents === false) {
                $violations[] = $this->violation(
                    'EXECUTION_SPEC_FILE_UNREADABLE',
                    $relativePath,
                    'Execution spec file could not be read.',
                );

                continue;
            }

            $fileHasViolations = false;

            if ($this->firstLine($contents) !== ExecutionSpecFilename::heading($parsedName['name'])) {
                $expectedHeading = ExecutionSpecFilename::heading($parsedName['name']);
                $actualHeading = $this->firstLine($contents);
                $violations[] = $this->violation(
                    'EXECUTION_SPEC_INVALID_HEADING',
                    $relativePath,
                    'Execution spec heading must mirror the filename only.',
                    [
                        'expected_heading' => $expectedHeading,
                        'actual_heading' => $actualHeading,
                    ],
                );
                $fileHasViolations = true;
            }

            $metadataViolations = $this->metadataViolations($relativePath, $contents);
            foreach ($metadataViolations as $metadataViolation) {
                $violations[] = $metadataViolation;
            }
            if ($metadataViolations !== []) {
                $fileHasViolations = true;
            }

            if ($placement['status'] === 'active' && !$fileHasViolations) {
                $activeSpecReferences[$relativePath] = $placement['feature'] . '/' . $parsedName['name'] . '.md';
                $activeSpecNames[$placement['feature']][$parsedName['name']] = true;
                $activeSpecPathsByFeature[$placement['feature']][$parsedName['name']] = $relativePath;
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

        $continuity = new ExecutionSpecIdContinuity();
        foreach ($continuityCandidates as $feature => $byLocation) {
            foreach ($byLocation as $location => $entries) {
                foreach ($continuity->gaps($entries) as $gap) {
                    $violations[] = $this->violation(
                        'EXECUTION_SPEC_ID_GAP',
                        (string) $gap['path'],
                        'Execution spec IDs must be contiguous. Skipping numbers violates execution-spec-system rules.',
                        [
                            'feature' => $feature,
                            'location' => $location,
                            'parent_id' => (string) ($gap['parent_id'] ?? 'top-level'),
                            'missing_id' => (string) $gap['missing_id'],
                            'expected_missing_id' => (string) $gap['missing_id'],
                            'next_observed_id' => (string) $gap['next_observed_id'],
                            'path' => (string) $gap['path'],
                        ],
                    );
                }
            }
        }

        $loggedSpecs = $this->implementationLogEntries($violations);
        if ($loggedSpecs !== null) {
            $loggedContinuity = [];
            foreach ($activeSpecReferences as $relativePath => $specReference) {
                if (isset($loggedSpecs[$specReference])) {
                    continue;
                }

                $violations[] = $this->violation(
                    'EXECUTION_SPEC_IMPLEMENTATION_LOG_MISSING',
                    $relativePath,
                    'Active execution specs must have a matching implementation-log entry.',
                    [
                        'spec' => $specReference,
                        'log_path' => 'docs/features/implementation-log.md',
                    ],
                );
            }

            foreach (array_keys($loggedSpecs) as $specReference) {
                if (preg_match('#^(?<feature>[a-z0-9]+(?:-[a-z0-9]+)*)/(?<name>[^/]+)\.md$#', $specReference, $matches) !== 1) {
                    continue;
                }

                $parsedName = ExecutionSpecFilename::parseName((string) $matches['name']);
                if ($parsedName === null) {
                    continue;
                }

                $loggedContinuity[(string) $matches['feature']][] = [
                    'id' => $parsedName['id'],
                    'segments' => $parsedName['segments'],
                    'path' => 'docs/features/implementation-log.md',
                ];
            }

            foreach ($loggedContinuity as $feature => $entries) {
                foreach ($continuity->gaps($entries) as $gap) {
                    $violations[] = $this->violation(
                        'EXECUTION_SPEC_IMPLEMENTATION_LOG_SKIPPED_ID',
                        'docs/features/implementation-log.md',
                        'Implementation-log entries must not skip execution spec IDs. Skipping numbers violates execution-spec-system rules.',
                        [
                            'feature' => $feature,
                            'missing_id' => (string) $gap['missing_id'],
                            'next_observed_id' => (string) $gap['next_observed_id'],
                        ],
                    );
                }
            }
        }

        $seenPlanIds = [];
        $planNamesByFeature = [];

        foreach ($this->planFiles() as $relativePath) {
            $checkedFiles++;
            $features[$this->planFeatureHint($relativePath)] = true;

            $placement = $this->classifyPlanPlacement($relativePath);
            if ($placement === null) {
                $violations[] = $this->violation(
                    'EXECUTION_SPEC_PLAN_INVALID_DIRECTORY',
                    $relativePath,
                    'Implementation plans must live at docs/features/<feature>/plans/<id>-<slug>.md.',
                );
                continue;
            }

            $features[$placement['feature']] = true;

            $parsedName = ExecutionSpecFilename::parseName($placement['name']);
            if ($parsedName === null) {
                $violations[] = $this->violation(
                    'EXECUTION_SPEC_PLAN_INVALID_FILENAME',
                    $relativePath,
                    'Implementation plan filenames must use <id>-<slug>.md with one or more dot-separated 3-digit ID segments.',
                );
                continue;
            }

            $seenPlanIds[$placement['feature']][$parsedName['id']][] = $relativePath;
            $planNamesByFeature[$placement['feature']][$parsedName['name']] = true;

            $contents = file_get_contents($this->paths->join($relativePath));
            if ($contents === false) {
                $violations[] = $this->violation(
                    'EXECUTION_SPEC_PLAN_FILE_UNREADABLE',
                    $relativePath,
                    'Implementation plan file could not be read.',
                );
                continue;
            }

            $fileHasViolations = false;
            $expectedHeading = '# Implementation Plan: ' . $parsedName['name'];
            if ($this->firstLine($contents) !== $expectedHeading) {
                $violations[] = $this->violation(
                    'EXECUTION_SPEC_PLAN_INVALID_HEADING',
                    $relativePath,
                    'Implementation plan heading must mirror the filename.',
                    [
                        'expected_heading' => $expectedHeading,
                        'actual_heading' => $this->firstLine($contents),
                    ],
                );
                $fileHasViolations = true;
            }

            $metadataViolations = $this->metadataViolations($relativePath, $contents, 'EXECUTION_SPEC_PLAN_FORBIDDEN_METADATA', 'Implementation plans must not define `%s` metadata inside the file.');
            foreach ($metadataViolations as $metadataViolation) {
                $violations[] = $metadataViolation;
            }
            if ($metadataViolations !== []) {
                $fileHasViolations = true;
            }

            if (!$fileHasViolations && !isset($activeSpecNames[$placement['feature']][$parsedName['name']])) {
                $violations[] = $this->violation(
                    'EXECUTION_SPEC_PLAN_ORPHAN',
                    $relativePath,
                    'Implementation plan filename must match an active execution spec filename in the same feature.',
                    [
                        'feature' => $placement['feature'],
                        'id' => $parsedName['id'],
                    ],
                );
            }
        }

        foreach ($seenPlanIds as $feature => $ids) {
            foreach ($ids as $id => $paths) {
                if (count($paths) < 2) {
                    continue;
                }

                sort($paths);
                $violations[] = $this->violation(
                    'EXECUTION_SPEC_PLAN_DUPLICATE_ID',
                    $paths[0],
                    'Implementation plan IDs must be unique within a feature.',
                    [
                        'feature' => $feature,
                        'id' => $id,
                        'paths' => $paths,
                    ],
                );
            }
        }

        if ($requirePlans) {
            foreach ($activeSpecNames as $feature => $names) {
                foreach (array_keys($names) as $name) {
                    if (isset($planNamesByFeature[$feature][$name])) {
                        continue;
                    }

                    $specPath = (string) ($activeSpecPathsByFeature[$feature][$name] ?? ('docs/features/' . $feature . '/specs/' . $name . '.md'));
                    $violations[] = $this->violation(
                        'EXECUTION_SPEC_PLAN_REQUIRED_MISSING',
                        $specPath,
                        'Active execution specs must have a matching implementation plan when --require-plans is enabled.',
                        [
                            'feature' => $feature,
                            'id' => (string) (ExecutionSpecFilename::parseName($name)['id'] ?? ''),
                            'plan_path' => 'docs/features/' . $feature . '/plans/' . $name . '.md',
                        ],
                    );
                }
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
        $files = [];
        foreach ([
            'docs/features/*/specs/*.md',
            'docs/features/*/specs/drafts/*.md',
            'docs/features/*/specs/*/*.md',
            'docs/specs/*.md',
            'docs/specs/*/*.md',
            'docs/specs/*/drafts/*.md',
            'docs/*/specs/*.md',
            'docs/*/specs/drafts/*.md',
            'docs/*/specs/*/*.md',
        ] as $pattern) {
            foreach (glob($this->paths->join($pattern)) ?: [] as $path) {
                if (!is_file($path)) {
                    continue;
                }

                $relativePath = $this->relativePath($path);
                if ($relativePath === null) {
                    continue;
                }

                $files[] = $relativePath;
            }
        }

        sort($files);

        return array_values(array_unique($files));
    }

    /**
     * @return list<string>
     */
    private function planFiles(): array
    {
        $files = [];

        foreach ([
            'docs/features/*/plans/*.md',
            'docs/features/*/plans/*/*.md',
            'docs/specs/plans/*.md',
            'docs/specs/*/plans/*.md',
            'docs/*/plans/*.md',
        ] as $pattern) {
            foreach (glob($this->paths->join($pattern)) ?: [] as $path) {
                if (!is_file($path)) {
                    continue;
                }

                $relativePath = $this->relativePath($path);
                if ($relativePath === null) {
                    continue;
                }

                $files[] = $relativePath;
            }
        }

        sort($files);

        return array_values(array_unique($files));
    }

    /**
     * @param list<array<string,mixed>> $violations
     * @return array<string,true>|null
     */
    private function implementationLogEntries(array &$violations): ?array
    {
        $relativePath = 'docs/features/implementation-log.md';
        $absolutePath = $this->paths->join($relativePath);

        if (!file_exists($absolutePath)) {
            return [];
        }

        if (is_dir($absolutePath)) {
            $violations[] = $this->violation(
                'EXECUTION_SPEC_IMPLEMENTATION_LOG_INVALID',
                $relativePath,
                'Execution spec implementation log must be a readable file.',
                ['path' => $relativePath],
            );

            return null;
        }

        $contents = file_get_contents($absolutePath);
        if ($contents === false) {
            $violations[] = $this->violation(
                'EXECUTION_SPEC_IMPLEMENTATION_LOG_INVALID',
                $relativePath,
                'Execution spec implementation log must be a readable file.',
                ['path' => $relativePath],
            );

            return null;
        }

        $entries = [];

        foreach (preg_split('/\R/', $contents) ?: [] as $line) {
            if (preg_match('/^- spec: (?<spec>.+)$/', $line, $matches) !== 1) {
                continue;
            }

            $entries[(string) $matches['spec']] = true;
        }

        return $entries;
    }

    /**
     * @return array{feature:string,status:string,name:string}|null
     */
    private function classifyPlacement(string $relativePath): ?array
    {
        if (preg_match('#^docs/features/(?<feature>[a-z0-9]+(?:-[a-z0-9]+)*)/specs/(?<name>[^/]+)\.md$#', $relativePath, $matches) === 1) {
            return [
                'feature' => (string) $matches['feature'],
                'status' => 'active',
                'name' => (string) $matches['name'],
            ];
        }

        if (preg_match('#^docs/features/(?<feature>[a-z0-9]+(?:-[a-z0-9]+)*)/specs/drafts/(?<name>[^/]+)\.md$#', $relativePath, $matches) === 1) {
            return [
                'feature' => (string) $matches['feature'],
                'status' => 'draft',
                'name' => (string) $matches['name'],
            ];
        }

        return null;
    }

    /**
     * @return array{feature:string,name:string}|null
     */
    private function classifyPlanPlacement(string $relativePath): ?array
    {
        if (preg_match('#^docs/features/(?<feature>[a-z0-9]+(?:-[a-z0-9]+)*)/plans/(?<name>[^/]+)\.md$#', $relativePath, $matches) !== 1) {
            return null;
        }

        return [
            'feature' => (string) $matches['feature'],
            'name' => (string) $matches['name'],
        ];
    }

    private function planFeatureHint(string $relativePath): string
    {
        if (preg_match('#^docs/features/(?<feature>[a-z0-9]+(?:-[a-z0-9]+)*)/#', $relativePath, $matches) === 1) {
            return (string) $matches['feature'];
        }

        return '_noncanonical';
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
    private function metadataViolations(
        string $relativePath,
        string $contents,
        string $code = 'EXECUTION_SPEC_FORBIDDEN_METADATA',
        string $messageTemplate = 'Execution specs must not define `%s` metadata inside the file.',
    ): array
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
                $code,
                $relativePath,
                sprintf($messageTemplate, $field),
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
