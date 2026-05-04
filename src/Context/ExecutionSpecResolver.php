<?php

declare(strict_types=1);

namespace Foundry\Context;

use Foundry\Support\FeatureNaming;
use Foundry\Support\FoundryError;
use Foundry\Support\Paths;

final class ExecutionSpecResolver
{
    public function __construct(
        private readonly Paths $paths,
        private readonly FeatureNameValidator $featureNameValidator = new FeatureNameValidator(),
    ) {}

    public function resolve(string $specId): ExecutionSpec
    {
        $specId = trim($specId);
        if ($specId === '') {
            throw new FoundryError(
                'EXECUTION_SPEC_ID_REQUIRED',
                'validation',
                [],
                'Execution spec id required.',
            );
        }

        [$resolvedId, $relativePath, $pathFeature] = $this->resolvePath($specId);
        $contents = file_get_contents($this->paths->join($relativePath));
        if ($contents === false) {
            throw new FoundryError(
                'EXECUTION_SPEC_FILE_UNREADABLE',
                'filesystem',
                ['spec_id' => $resolvedId, 'path' => $relativePath, 'feature' => $pathFeature],
                'Execution spec could not be read.',
            );
        }

        $specName = basename($relativePath, '.md');
        $expectedHeading = ExecutionSpecFilename::heading($specName);
        if ($this->firstLine($contents) !== $expectedHeading) {
            throw new FoundryError(
                'EXECUTION_SPEC_HEADING_NON_CANONICAL',
                'validation',
                [
                    'spec_id' => $resolvedId,
                    'path' => $relativePath,
                    'feature' => $pathFeature,
                    'expected_heading' => $expectedHeading,
                ],
                'Execution spec heading must mirror the filename only.',
            );
        }

        $featureSection = $this->featureFromContents($contents);
        if ($featureSection === null || trim($featureSection) === '') {
            throw new FoundryError(
                'EXECUTION_SPEC_FEATURE_SECTION_MISSING',
                'validation',
                ['spec_id' => $resolvedId, 'path' => $relativePath, 'feature' => $pathFeature],
                'Execution spec must declare the target feature in a ## Feature section.',
            );
        }

        $feature = FeatureNaming::canonical($featureSection);
        $validation = $this->featureNameValidator->validate($feature);
        if (!$validation->valid) {
            throw new FoundryError(
                'EXECUTION_SPEC_FEATURE_INVALID',
                'validation',
                ['spec_id' => $resolvedId, 'path' => $relativePath, 'feature' => $feature],
                'Execution spec feature must be lowercase kebab-case.',
            );
        }

        if ($feature !== $pathFeature) {
            throw new FoundryError(
                'EXECUTION_SPEC_FEATURE_MISMATCH',
                'validation',
                [
                    'spec_id' => $resolvedId,
                    'path' => $relativePath,
                    'feature' => $feature,
                    'path_feature' => $pathFeature,
                ],
                'Execution spec path feature and ## Feature section must match.',
            );
        }

        $parsedName = ExecutionSpecFilename::parseName($specName);
        if ($parsedName === null) {
            throw new FoundryError(
                'EXECUTION_SPEC_PATH_NON_CANONICAL',
                'validation',
                ['path' => $relativePath],
                'Execution spec ids must resolve to Features/<FeaturePascalName>/specs/<id>-<slug>.md (canonical) or docs/features/<feature>/specs/<id>-<slug>.md (legacy compatibility), where <id> uses one or more dot-separated 3-digit segments.',
            );
        }

        return new ExecutionSpec(
            specId: $resolvedId,
            feature: $feature,
            path: $relativePath,
            purpose: trim($this->sectionBody($contents, 'Purpose') ?? ''),
            scope: $this->meaningfulItems($this->sectionBody($contents, 'Scope') ?? ''),
            constraints: $this->meaningfulItems($this->sectionBody($contents, 'Constraints') ?? ''),
            requestedChanges: $this->requestedChangeItems($this->sectionBody($contents, 'Requested Changes') ?? ''),
            name: $parsedName['name'],
            id: $parsedName['id'],
            parentId: $parsedName['parent_id'],
        );
    }

    public function resolveWithinFeature(string $feature, string $id): ExecutionSpec
    {
        $feature = FeatureNaming::canonical(trim($feature));
        $id = trim($id);

        $validation = $this->featureNameValidator->validate($feature);
        if (!$validation->valid) {
            throw new FoundryError(
                'EXECUTION_SPEC_FEATURE_INVALID',
                'validation',
                ['feature' => $feature, 'id' => $id],
                'Execution spec feature must be lowercase kebab-case.',
            );
        }

        if (preg_match('/^' . ExecutionSpecFilename::ID_PATTERN . '$/', $id) !== 1) {
            throw new FoundryError(
                'EXECUTION_SPEC_ID_INVALID',
                'validation',
                ['feature' => $feature, 'id' => $id],
                'Execution spec id must use one or more dot-separated 3-digit segments.',
            );
        }

        if (!$this->featureExists($feature)) {
            throw new FoundryError(
                'EXECUTION_SPEC_FEATURE_NOT_FOUND',
                'filesystem',
                ['feature' => $feature, 'id' => $id],
                'Execution spec feature not found.',
            );
        }

        $activeMatches = $this->matchesById($feature, $id, includeDrafts: false);
        if (count($activeMatches) > 1) {
            throw new FoundryError(
                'EXECUTION_SPEC_AMBIGUOUS',
                'validation',
                ['feature' => $feature, 'id' => $id, 'matches' => $activeMatches],
                'Execution spec id is ambiguous within the feature.',
            );
        }

        if ($activeMatches !== []) {
            return $this->resolve($activeMatches[0]);
        }

        $draftMatches = $this->matchesById($feature, $id, includeDrafts: true);
        if ($draftMatches !== []) {
            throw new FoundryError(
                'EXECUTION_SPEC_DRAFT_ONLY',
                'validation',
                ['feature' => $feature, 'id' => $id, 'matches' => $draftMatches],
                'Execution spec id exists only in drafts and must be promoted before implementation.',
            );
        }

        throw new FoundryError(
            'EXECUTION_SPEC_NOT_FOUND',
            'filesystem',
            ['feature' => $feature, 'id' => $id],
            'Active execution spec id not found for feature.',
        );
    }

    /**
     * @return array{0:string,1:string,2:string}
     */
    private function resolvePath(string $specId): array
    {
        $pathInput = str_replace('\\', '/', $specId);

        if (str_starts_with($pathInput, 'docs/') || str_starts_with($pathInput, 'Features/')) {
            $path = str_ends_with($pathInput, '.md') ? $pathInput : $pathInput . '.md';
            $draftPath = ExecutionSpecFilename::parseDraftPath($path);
            if ($draftPath !== null) {
                throw new FoundryError(
                    'EXECUTION_SPEC_DRAFT_ONLY',
                    'validation',
                    [
                        'spec_id' => $draftPath['feature'] . '/' . $draftPath['name'],
                        'path' => $path,
                        'feature' => $draftPath['feature'],
                        'id' => $draftPath['id'],
                        'matches' => [$path],
                    ],
                    'Execution spec id exists only in drafts and must be promoted before implementation.',
                );
            }

            return $this->canonicalPathParts($path);
        }

        $trimmed = trim($pathInput, '/');

        if (substr_count($trimmed, '/') === 1) {
            [$feature, $name] = explode('/', $trimmed, 2);
            $feature = FeatureNaming::canonical($feature);
            $name = $this->stripMarkdownExtension($name);
            $path = $this->activeSpecPathForFeature($feature, $name);

            return $this->canonicalPathParts($path);
        }

        if (str_contains($trimmed, '/')) {
            throw new FoundryError(
                'EXECUTION_SPEC_PATH_NON_CANONICAL',
                'validation',
                ['spec_id' => $specId],
                'Execution spec ids must resolve to Features/<FeaturePascalName>/specs/<id>-<slug>.md (canonical) or docs/features/<feature>/specs/<id>-<slug>.md (legacy compatibility), where <id> uses one or more dot-separated 3-digit segments.',
            );
        }

        $basename = $this->stripMarkdownExtension($trimmed);
        if (!ExecutionSpecFilename::isCanonicalName($basename)) {
            throw new FoundryError(
                'EXECUTION_SPEC_PATH_NON_CANONICAL',
                'validation',
                ['spec_id' => $specId],
                'Execution spec ids must resolve to Features/<FeaturePascalName>/specs/<id>-<slug>.md (canonical) or docs/features/<feature>/specs/<id>-<slug>.md (legacy compatibility), where <id> uses one or more dot-separated 3-digit segments.',
            );
        }

        $relativeMatches = $this->collectActiveMatches($basename);

        sort($relativeMatches);

        if ($relativeMatches === []) {
            $draftMatches = $this->collectDraftMatches($basename);

            sort($draftMatches);
            if ($draftMatches !== []) {
                $firstDraft = ExecutionSpecFilename::parseDraftPath($draftMatches[0]);

                throw new FoundryError(
                    'EXECUTION_SPEC_DRAFT_ONLY',
                    'validation',
                    [
                        'spec_id' => $specId,
                        'feature' => (string) ($firstDraft['feature'] ?? ''),
                        'id' => (string) ($firstDraft['id'] ?? ''),
                        'matches' => $draftMatches,
                    ],
                    'Execution spec id exists only in drafts and must be promoted before implementation.',
                );
            }

            throw new FoundryError(
                'EXECUTION_SPEC_NOT_FOUND',
                'filesystem',
                ['spec_id' => $specId],
                'Execution spec not found.',
            );
        }

        if (count($relativeMatches) > 1) {
            throw new FoundryError(
                'EXECUTION_SPEC_AMBIGUOUS',
                'validation',
                ['spec_id' => $specId, 'matches' => $relativeMatches],
                'Execution spec id is ambiguous.',
            );
        }

        return $this->canonicalPathParts($relativeMatches[0]);
    }

    /**
     * @return array{0:string,1:string,2:string}
     */
    private function canonicalPathParts(string $relativePath): array
    {
        $parsedPath = ExecutionSpecFilename::parseActivePath($relativePath);
        if ($parsedPath === null) {
            throw new FoundryError(
                'EXECUTION_SPEC_PATH_NON_CANONICAL',
                'validation',
                ['path' => $relativePath],
                'Execution spec ids must resolve to Features/<FeaturePascalName>/specs/<id>-<slug>.md (canonical) or docs/features/<feature>/specs/<id>-<slug>.md (legacy compatibility), where <id> uses one or more dot-separated 3-digit segments.',
            );
        }

        if (!is_file($this->paths->join($relativePath))) {
            throw new FoundryError(
                'EXECUTION_SPEC_NOT_FOUND',
                'filesystem',
                ['spec_id' => $parsedPath['feature'] . '/' . $parsedPath['name'], 'path' => $relativePath, 'feature' => $parsedPath['feature']],
                'Execution spec not found.',
            );
        }

        return [$parsedPath['feature'] . '/' . $parsedPath['name'], $relativePath, $parsedPath['feature']];
    }

    private function stripMarkdownExtension(string $value): string
    {
        return str_ends_with($value, '.md')
            ? substr($value, 0, -strlen('.md'))
            : $value;
    }

    private function relativePath(string $absolutePath): ?string
    {
        $root = rtrim($this->paths->root(), '/');
        if (!str_starts_with($absolutePath, $root . '/')) {
            return null;
        }

        return substr($absolutePath, strlen($root) + 1);
    }

    /**
     * @return list<string>
     */
    private function matchesById(string $feature, string $id, bool $includeDrafts): array
    {
        $patterns = [
            'Features/' . $this->pascalFromSlug($feature) . '/specs/*.md',
            'docs/features/' . $feature . '/specs/*.md',
        ];
        if ($includeDrafts) {
            $patterns[] = 'Features/' . $this->pascalFromSlug($feature) . '/specs/drafts/*.md';
            $patterns[] = 'docs/features/' . $feature . '/specs/drafts/*.md';
        }

        $matches = [];

        foreach ($patterns as $pattern) {
            foreach (glob($this->paths->join($pattern)) ?: [] as $path) {
                $relative = $this->relativePath($path);
                if ($relative === null) {
                    continue;
                }

                $parsed = ExecutionSpecFilename::parseActivePath($relative)
                    ?? ExecutionSpecFilename::parseDraftPath($relative);
                if ($parsed === null || $parsed['feature'] !== $feature || $parsed['id'] !== $id) {
                    continue;
                }

                $matches[] = $relative;
            }
        }

        return $this->canonicalPreferredByName($matches);
    }

    private function featureExists(string $feature): bool
    {
        $canonicalRoot = 'Features/' . $this->pascalFromSlug($feature);
        $paths = [
            $canonicalRoot,
            $canonicalRoot . '/specs/drafts',
            $canonicalRoot . '/' . $feature . '.spec.md',
            $canonicalRoot . '/' . $feature . '.md',
            $canonicalRoot . '/' . $feature . '.decisions.md',
            'docs/features/' . $feature,
            'docs/features/' . $feature . '/specs/drafts',
            'docs/features/' . $feature . '/' . $feature . '.spec.md',
            'docs/features/' . $feature . '/' . $feature . '.md',
            'docs/features/' . $feature . '/' . $feature . '.decisions.md',
        ];

        foreach ($paths as $path) {
            if (is_dir($this->paths->join($path)) || is_file($this->paths->join($path))) {
                return true;
            }
        }

        return false;
    }

    private function activeSpecPathForFeature(string $feature, string $name): string
    {
        $canonical = 'Features/' . $this->pascalFromSlug($feature) . '/specs/' . $name . '.md';
        if (is_file($this->paths->join($canonical))) {
            return $canonical;
        }

        return 'docs/features/' . $feature . '/specs/' . $name . '.md';
    }

    /**
     * @return list<string>
     */
    private function collectActiveMatches(string $basename): array
    {
        $matches = [];

        foreach ([
            'Features/*/specs/' . $basename . '.md',
            'docs/features/*/specs/' . $basename . '.md',
        ] as $pattern) {
            foreach (glob($this->paths->join($pattern)) ?: [] as $match) {
                $relative = $this->relativePath($match);
                if ($relative === null || ExecutionSpecFilename::parseActivePath($relative) === null) {
                    continue;
                }

                $matches[] = $relative;
            }
        }

        return $this->canonicalPreferredByName($matches);
    }

    /**
     * @return list<string>
     */
    private function collectDraftMatches(string $basename): array
    {
        $matches = [];

        foreach ([
            'Features/*/specs/drafts/' . $basename . '.md',
            'docs/features/*/specs/drafts/' . $basename . '.md',
        ] as $pattern) {
            foreach (glob($this->paths->join($pattern)) ?: [] as $match) {
                $relative = $this->relativePath($match);
                if ($relative === null || ExecutionSpecFilename::parseDraftPath($relative) === null) {
                    continue;
                }

                $matches[] = $relative;
            }
        }

        return $this->canonicalPreferredByName($matches);
    }

    private function pascalFromSlug(string $slug): string
    {
        $parts = array_filter(explode('-', $slug), static fn(string $part): bool => $part !== '');

        return implode('', array_map(static fn(string $part): string => ucfirst($part), $parts));
    }

    /**
     * @param list<string> $paths
     * @return list<string>
     */
    private function canonicalPreferredByName(array $paths): array
    {
        $paths = array_values(array_unique($paths));
        usort($paths, static function (string $left, string $right): int {
            $leftCanonical = str_starts_with($left, 'Features/');
            $rightCanonical = str_starts_with($right, 'Features/');
            if ($leftCanonical !== $rightCanonical) {
                return $leftCanonical ? -1 : 1;
            }

            return strcmp($left, $right);
        });

        $deduped = [];
        $seen = [];

        foreach ($paths as $path) {
            $parsed = ExecutionSpecFilename::parseActivePath($path) ?? ExecutionSpecFilename::parseDraftPath($path);
            if ($parsed === null) {
                continue;
            }

            $key = $parsed['feature'] . '/' . $parsed['name'];
            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $deduped[] = $path;
        }

        return $deduped;
    }

    private function featureFromContents(string $contents): ?string
    {
        $section = $this->sectionBody($contents, 'Feature');
        if ($section === null) {
            return null;
        }

        foreach ($this->meaningfulItems($section) as $item) {
            return $item;
        }

        return null;
    }

    private function firstLine(string $contents): string
    {
        $firstLine = strtok(str_replace("\r\n", "\n", $contents), "\n");

        return $firstLine === false ? '' : trim($firstLine);
    }

    private function sectionBody(string $contents, string $section): ?string
    {
        $pattern = '/^## ' . preg_quote($section, '/') . '\s*$(.*?)(?=^## |\z)/ms';
        if (preg_match($pattern, $contents, $matches) !== 1) {
            return null;
        }

        return trim((string) ($matches[1] ?? ''));
    }

    /**
     * @return list<string>
     */
    private function requestedChangeItems(string $body): array
    {
        return $this->meaningfulItems($this->normalizeRequestedChangesBody($body));
    }

    private function normalizeRequestedChangesBody(string $body): string
    {
        $lines = preg_split('/\R/', $body) ?: [];
        $lineCount = count($lines);

        for ($index = 0; $index < $lineCount; $index++) {
            $line = (string) $lines[$index];
            $trimmed = trim($line);

            if (!$this->isNegativeLeadIn($trimmed)) {
                continue;
            }

            $nextIndex = $index + 1;
            while ($nextIndex < $lineCount) {
                $nextLine = (string) $lines[$nextIndex];
                if (trim($nextLine) === '') {
                    break;
                }

                if (preg_match('/^(\s*)((?:[-*]|\d+\.))\s+(.+)$/', $nextLine, $matches) !== 1) {
                    break;
                }

                $bulletItem = trim((string) $matches[3]);
                if ($this->shouldMergeNegativeLeadInBullet($bulletItem)) {
                    $lines[$nextIndex] = (string) $matches[1]
                        . (string) $matches[2]
                        . ' '
                        . $this->mergeNegativeLeadInBullet($trimmed, $bulletItem);
                }

                $nextIndex++;
            }
        }

        return implode(PHP_EOL, $lines);
    }

    private function isNegativeLeadIn(string $line): bool
    {
        $trimmed = trim($line);

        return $trimmed !== ''
            && str_ends_with($trimmed, ':')
            && $this->containsNegativeRequirement($trimmed);
    }

    private function shouldMergeNegativeLeadInBullet(string $item): bool
    {
        $trimmed = ltrim($item);
        if ($trimmed === '' || $this->containsNegativeRequirement($trimmed)) {
            return false;
        }

        $firstCharacter = substr($trimmed, 0, 1);

        return $firstCharacter !== '' && !preg_match('/[A-Z]/', $firstCharacter);
    }

    private function mergeNegativeLeadInBullet(string $leadIn, string $item): string
    {
        $prefix = rtrim(trim($leadIn), ':');
        $combined = trim($prefix . ' ' . ltrim($item));

        if (!preg_match('/[.!?]$/', $combined)) {
            $combined .= '.';
        }

        return $combined;
    }

    private function containsNegativeRequirement(string $item): bool
    {
        $normalized = strtolower($item);

        return str_contains($normalized, 'do not')
            || str_contains($normalized, 'must not')
            || str_contains($normalized, 'never ')
            || str_contains($normalized, 'cannot ')
            || str_contains($normalized, "can't ");
    }

    /**
     * @return list<string>
     */
    private function meaningfulItems(string $body): array
    {
        $items = [];
        $paragraph = [];

        foreach (preg_split('/\R/', $body) ?: [] as $line) {
            $trimmed = trim($line);
            if ($trimmed === '') {
                if ($paragraph !== []) {
                    $items[] = trim(implode(' ', $paragraph));
                    $paragraph = [];
                }

                continue;
            }

            if (preg_match('/^(?:[-*]|\d+\.)\s+(.+)$/', $trimmed, $matches) === 1) {
                if ($paragraph !== []) {
                    $items[] = trim(implode(' ', $paragraph));
                    $paragraph = [];
                }

                $items[] = trim((string) $matches[1]);

                continue;
            }

            $paragraph[] = $trimmed;
        }

        if ($paragraph !== []) {
            $items[] = trim(implode(' ', $paragraph));
        }

        return array_values(array_filter(
            array_map(static fn(string $item): string => trim($item), $items),
            static fn(string $item): bool => $item !== '',
        ));
    }
}
