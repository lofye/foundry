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
                'Execution spec ids must resolve to docs/specs/<feature>/<id>-<slug>.md, where <id> uses one or more dot-separated 3-digit segments.',
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

    /**
     * @return array{0:string,1:string,2:string}
     */
    private function resolvePath(string $specId): array
    {
        $pathInput = str_replace('\\', '/', $specId);

        if (str_starts_with($pathInput, 'docs/specs/')) {
            $path = str_ends_with($pathInput, '.md') ? $pathInput : $pathInput . '.md';

            return $this->canonicalPathParts($path);
        }

        $trimmed = trim($pathInput, '/');

        if (substr_count($trimmed, '/') === 1) {
            [$feature, $name] = explode('/', $trimmed, 2);
            $feature = FeatureNaming::canonical($feature);
            $name = $this->stripMarkdownExtension($name);
            $path = 'docs/specs/' . $feature . '/' . $name . '.md';

            return $this->canonicalPathParts($path);
        }

        if (str_contains($trimmed, '/')) {
            throw new FoundryError(
                'EXECUTION_SPEC_PATH_NON_CANONICAL',
                'validation',
                ['spec_id' => $specId],
                'Execution spec ids must resolve to docs/specs/<feature>/<id>-<slug>.md, where <id> uses one or more dot-separated 3-digit segments.',
            );
        }

        $basename = $this->stripMarkdownExtension($trimmed);
        if (!ExecutionSpecFilename::isCanonicalName($basename)) {
            throw new FoundryError(
                'EXECUTION_SPEC_PATH_NON_CANONICAL',
                'validation',
                ['spec_id' => $specId],
                'Execution spec ids must resolve to docs/specs/<feature>/<id>-<slug>.md, where <id> uses one or more dot-separated 3-digit segments.',
            );
        }

        $matches = glob($this->paths->join('docs/specs/*/' . $basename . '.md')) ?: [];
        $relativeMatches = [];

        foreach ($matches as $match) {
            $relative = $this->relativePath($match);
            if ($relative === null) {
                continue;
            }

            if (ExecutionSpecFilename::parseActivePath($relative) === null) {
                continue;
            }

            $relativeMatches[] = $relative;
        }

        sort($relativeMatches);

        if ($relativeMatches === []) {
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
                'Execution spec ids must resolve to docs/specs/<feature>/<id>-<slug>.md, where <id> uses one or more dot-separated 3-digit segments.',
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
