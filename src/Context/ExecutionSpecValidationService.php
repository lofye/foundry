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
        'Features/README.md',
        'Features/implementation.log',
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
        $activeSpecLocations = [];

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
                $activeSpecLocations[$placement['feature']][$parsedName['name']][$placement['workspace']][] = $relativePath;
            }
        }

        foreach ($activeSpecLocations as $feature => $specNames) {
            foreach ($specNames as $name => $locations) {
                if (!isset($locations['canonical'], $locations['legacy'])) {
                    continue;
                }

                $canonicalPaths = array_values(array_unique(array_map('strval', (array) $locations['canonical'])));
                $legacyPaths = array_values(array_unique(array_map('strval', (array) $locations['legacy'])));
                sort($canonicalPaths);
                sort($legacyPaths);

                $violations[] = $this->violation(
                    'FEATURE_DUPLICATE_CANONICAL_AND_LEGACY',
                    $canonicalPaths[0] ?? ('Features/' . $name . '.md'),
                    'Execution spec exists in both canonical and legacy feature workspaces.',
                    [
                        'feature' => $feature,
                        'spec_name' => $name,
                        'canonical_paths' => $canonicalPaths,
                        'legacy_paths' => $legacyPaths,
                    ],
                );
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

        $logPath = $this->implementationLogPath();
        $loggedSpecs = $this->implementationLogEntries($violations, $logPath);
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
                        'log_path' => $logPath,
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
                        $logPath,
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
                            'plan_path' => $this->canonicalPlanPath($feature, $name),
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
            'Features/*/specs/*.md',
            'Features/*/specs/drafts/*.md',
            'Features/*/specs/*/*.md',
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
            'Features/*/plans/*.md',
            'Features/*/plans/*/*.md',
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
    private function implementationLogEntries(array &$violations, string $relativePath): ?array
    {
        $entries = [];
        $candidatePaths = [$relativePath];

        if ($relativePath === 'Features/implementation.log') {
            $candidatePaths[] = 'docs/features/implementation-log.md';
        } elseif ($relativePath === 'docs/features/implementation-log.md') {
            $candidatePaths[] = 'Features/implementation.log';
        }

        foreach (array_values(array_unique($candidatePaths)) as $candidatePath) {
            $absolutePath = $this->paths->join($candidatePath);
            if (!file_exists($absolutePath)) {
                continue;
            }

            if (is_dir($absolutePath)) {
                $violations[] = $this->violation(
                    'EXECUTION_SPEC_IMPLEMENTATION_LOG_INVALID',
                    $candidatePath,
                    'Execution spec implementation log must be a readable file.',
                    ['path' => $candidatePath],
                );

                return null;
            }

            $contents = file_get_contents($absolutePath);
            if ($contents === false) {
                $violations[] = $this->violation(
                    'EXECUTION_SPEC_IMPLEMENTATION_LOG_INVALID',
                    $candidatePath,
                    'Execution spec implementation log must be a readable file.',
                    ['path' => $candidatePath],
                );

                return null;
            }

            foreach (preg_split('/\R/', $contents) ?: [] as $line) {
                if (preg_match('/^- spec: (?<spec>.+)$/', $line, $matches) !== 1) {
                    continue;
                }

                $entries[(string) $matches['spec']] = true;
            }
        }

        return $entries;
    }

    /**
     * @return array{feature:string,status:string,name:string,workspace:string}|null
     */
    private function classifyPlacement(string $relativePath): ?array
    {
        if (preg_match('#^Features/(?<feature_dir>[A-Z][A-Za-z0-9]*)/specs/(?<name>[^/]+)\.md$#', $relativePath, $matches) === 1) {
            return [
                'feature' => $this->slugFromPascal((string) $matches['feature_dir']),
                'status' => 'active',
                'name' => (string) $matches['name'],
                'workspace' => 'canonical',
            ];
        }

        if (preg_match('#^Features/(?<feature_dir>[A-Z][A-Za-z0-9]*)/specs/drafts/(?<name>[^/]+)\.md$#', $relativePath, $matches) === 1) {
            return [
                'feature' => $this->slugFromPascal((string) $matches['feature_dir']),
                'status' => 'draft',
                'name' => (string) $matches['name'],
                'workspace' => 'canonical',
            ];
        }

        if (preg_match('#^docs/features/(?<feature>[a-z0-9]+(?:-[a-z0-9]+)*)/specs/(?<name>[^/]+)\.md$#', $relativePath, $matches) === 1) {
            return [
                'feature' => (string) $matches['feature'],
                'status' => 'active',
                'name' => (string) $matches['name'],
                'workspace' => 'legacy',
            ];
        }

        if (preg_match('#^docs/features/(?<feature>[a-z0-9]+(?:-[a-z0-9]+)*)/specs/drafts/(?<name>[^/]+)\.md$#', $relativePath, $matches) === 1) {
            return [
                'feature' => (string) $matches['feature'],
                'status' => 'draft',
                'name' => (string) $matches['name'],
                'workspace' => 'legacy',
            ];
        }

        return null;
    }

    /**
     * @return array{feature:string,name:string}|null
     */
    private function classifyPlanPlacement(string $relativePath): ?array
    {
        if (preg_match('#^Features/(?<feature_dir>[A-Z][A-Za-z0-9]*)/plans/(?<name>[^/]+)\.md$#', $relativePath, $matches) === 1) {
            return [
                'feature' => $this->slugFromPascal((string) $matches['feature_dir']),
                'name' => (string) $matches['name'],
            ];
        }

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
        if (preg_match('#^Features/(?<feature_dir>[A-Z][A-Za-z0-9]*)/#', $relativePath, $matches) === 1) {
            return $this->slugFromPascal((string) $matches['feature_dir']);
        }

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

    private function implementationLogPath(): string
    {
        $canonical = $this->paths->join('Features/implementation.log');
        $legacy = $this->paths->join('docs/features/implementation-log.md');

        if (is_file($canonical) || is_dir($this->paths->join('Features'))) {
            return 'Features/implementation.log';
        }

        if (is_file($legacy) || is_dir($this->paths->join('docs/features'))) {
            return 'docs/features/implementation-log.md';
        }

        return 'Features/implementation.log';
    }

    private function canonicalPlanPath(string $feature, string $name): string
    {
        $canonical = 'Features/' . $this->pascalFromSlug($feature) . '/plans/' . $name . '.md';
        if (is_file($this->paths->join($canonical)) || is_dir($this->paths->join('Features/' . $this->pascalFromSlug($feature)))) {
            return $canonical;
        }

        return 'docs/features/' . $feature . '/plans/' . $name . '.md';
    }

    private function pascalFromSlug(string $slug): string
    {
        $parts = array_filter(explode('-', $slug), static fn(string $part): bool => $part !== '');

        return implode('', array_map(static fn(string $part): string => ucfirst($part), $parts));
    }

    private function slugFromPascal(string $value): string
    {
        $hyphenated = (string) preg_replace('/(?<!^)[A-Z]/', '-$0', $value);

        return strtolower($hyphenated);
    }
}
