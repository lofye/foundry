<?php

declare(strict_types=1);

namespace Foundry\Context;

use Foundry\Support\FoundryError;
use Foundry\Support\Paths;

final class ExecutionSpecDraftService
{
    /**
     * @var list<string>
     */
    private const LOW_INFORMATION_SLUGS = [
        'draft',
        'misc',
        'new',
        'placeholder',
        'spec',
        'temp',
        'test',
        'tmp',
        'todo',
        'untitled',
    ];

    public function __construct(
        private readonly Paths $paths,
        private readonly FeatureNameValidator $featureNameValidator = new FeatureNameValidator(),
        private readonly ?ExecutionSpecCatalog $catalog = null,
    ) {}

    /**
     * @return array{
     *     success:bool,
     *     feature:string,
     *     provided_feature:string,
     *     id:?string,
     *     slug:?string,
     *     provided_slug:string,
     *     path:?string,
     *     reason:?string,
     *     required_actions:list<string>
     * }
     */
    public function createDraft(string $providedFeature, string $providedSlug): array
    {
        $providedFeature = trim($providedFeature);
        $providedSlug = trim($providedSlug);

        $featureValidation = $this->featureNameValidator->validate($providedFeature);
        if (!$featureValidation->valid) {
            return $this->failure(
                providedFeature: $providedFeature,
                providedSlug: $providedSlug,
                reason: 'invalid feature name',
                requiredActions: [
                    'Use lowercase kebab-case',
                    'Example: execution-spec-system',
                ],
            );
        }

        $normalizedSlug = $this->normalizeSlug($providedSlug);
        if ($normalizedSlug === null) {
            return $this->failure(
                providedFeature: $providedFeature,
                providedSlug: $providedSlug,
                reason: 'invalid slug',
                requiredActions: [
                    'Provide a meaningful kebab-case slug',
                    'Example: add-cli-command',
                ],
            );
        }

        $catalog = $this->catalog ?? new ExecutionSpecCatalog($this->paths);

        try {
            $nextId = $catalog->nextRootId($providedFeature);
        } catch (FoundryError $error) {
            if ($error->errorCode === 'EXECUTION_SPEC_ID_ALLOCATION_FAILED') {
                return $this->failure(
                    providedFeature: $providedFeature,
                    providedSlug: $providedSlug,
                    reason: 'could not allocate next spec ID',
                    requiredActions: [
                        'Run `foundry spec:validate`',
                        'Resolve duplicate or invalid spec state in this feature',
                    ],
                );
            }

            throw $error;
        }

        $relativeDraftDirectory = 'docs/specs/' . $providedFeature . '/drafts';
        $absoluteDraftDirectory = $this->paths->join($relativeDraftDirectory);
        if (file_exists($absoluteDraftDirectory) && !is_dir($absoluteDraftDirectory)) {
            return $this->failure(
                providedFeature: $providedFeature,
                providedSlug: $providedSlug,
                reason: 'could not allocate next spec ID',
                requiredActions: [
                    'Run `foundry spec:validate`',
                    'Resolve duplicate or invalid spec state in this feature',
                ],
            );
        }

        if (!is_dir($absoluteDraftDirectory) && !mkdir($absoluteDraftDirectory, 0777, true) && !is_dir($absoluteDraftDirectory)) {
            throw new FoundryError(
                'EXECUTION_SPEC_DRAFT_DIRECTORY_CREATE_FAILED',
                'filesystem',
                ['path' => $relativeDraftDirectory],
                'Could not create draft execution spec directory.',
            );
        }

        $specName = $nextId . '-' . $normalizedSlug;
        $relativePath = $relativeDraftDirectory . '/' . $specName . '.md';
        $absolutePath = $this->paths->join($relativePath);

        if (file_exists($absolutePath)) {
            return $this->failure(
                providedFeature: $providedFeature,
                providedSlug: $providedSlug,
                reason: 'target file already exists',
                id: $nextId,
                slug: $normalizedSlug,
                path: $relativePath,
                requiredActions: [
                    'Choose a different slug',
                    'Or inspect existing specs in this feature',
                ],
            );
        }

        $contents = $this->renderDraftSpec($specName, $providedFeature);
        if (file_put_contents($absolutePath, $contents) === false) {
            throw new FoundryError(
                'EXECUTION_SPEC_DRAFT_WRITE_FAILED',
                'filesystem',
                ['path' => $relativePath],
                'Could not write draft execution spec.',
            );
        }

        return [
            'success' => true,
            'feature' => $providedFeature,
            'provided_feature' => $providedFeature,
            'id' => $nextId,
            'slug' => $normalizedSlug,
            'provided_slug' => $providedSlug,
            'path' => $relativePath,
            'reason' => null,
            'required_actions' => [
                'Fill in the spec sections',
                'Keep the filename unchanged',
                'Promote by moving it out of drafts when ready',
            ],
        ];
    }

    private function normalizeSlug(string $value): ?string
    {
        $normalized = strtolower(trim($value));
        $normalized = preg_replace('/[^a-z0-9]+/', '-', $normalized) ?? $normalized;
        $normalized = trim($normalized, '-');

        if ($normalized === '') {
            return null;
        }

        $tokens = array_values(array_filter(explode('-', $normalized), static fn(string $token): bool => $token !== ''));
        if ($tokens === []) {
            return null;
        }

        $lowInformation = array_fill_keys(self::LOW_INFORMATION_SLUGS, true);
        $allLowInformation = array_reduce($tokens, static function (bool $carry, string $token) use ($lowInformation): bool {
            return $carry && isset($lowInformation[$token]);
        }, true);

        return $allLowInformation ? null : $normalized;
    }

    private function renderDraftSpec(string $specName, string $featureName): string
    {
        $relativePath = 'stubs/specs/draft-execution-spec.stub.md';
        $contents = file_get_contents($this->paths->frameworkJoin($relativePath));

        if ($contents === false) {
            throw new FoundryError(
                'EXECUTION_SPEC_DRAFT_STUB_MISSING',
                'filesystem',
                ['path' => $relativePath],
                'Draft execution spec stub could not be read.',
            );
        }

        $rendered = str_replace(
            ['{{spec_name}}', '{{feature}}'],
            [$specName, $featureName],
            $contents,
        );

        if ($this->firstLine($rendered) !== ExecutionSpecFilename::heading($specName)) {
            throw new FoundryError(
                'EXECUTION_SPEC_DRAFT_STUB_INVALID',
                'validation',
                ['spec_name' => $specName],
                'Draft execution spec stub must render a canonical filename-only heading.',
            );
        }

        return $rendered;
    }

    /**
     * @param list<string> $requiredActions
     * @return array{
     *     success:bool,
     *     feature:string,
     *     provided_feature:string,
     *     id:?string,
     *     slug:?string,
     *     provided_slug:string,
     *     path:?string,
     *     reason:?string,
     *     required_actions:list<string>
     * }
     */
    private function failure(
        string $providedFeature,
        string $providedSlug,
        string $reason,
        array $requiredActions,
        ?string $id = null,
        ?string $slug = null,
        ?string $path = null,
    ): array {
        return [
            'success' => false,
            'feature' => $providedFeature,
            'provided_feature' => $providedFeature,
            'id' => $id,
            'slug' => $slug,
            'provided_slug' => $providedSlug,
            'path' => $path,
            'reason' => $reason,
            'required_actions' => $requiredActions,
        ];
    }

    private function firstLine(string $contents): string
    {
        $firstLine = strtok(str_replace("\r\n", "\n", $contents), "\n");

        return $firstLine === false ? '' : trim($firstLine);
    }
}
