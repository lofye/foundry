<?php

declare(strict_types=1);

namespace Foundry\Context;

use Foundry\Context\Validation\ValidationIssue;
use Foundry\Support\FeatureNaming;
use Foundry\Support\FoundryError;
use Foundry\Support\Paths;

final class ContextInitService
{
    /**
     * @var array<string,array{path:string,stub:string}>
     */
    private const array FILES = [
        'spec' => [
            'path' => 'specPath',
            'stub' => 'spec.stub.md',
        ],
        'state' => [
            'path' => 'statePath',
            'stub' => 'state.stub.md',
        ],
        'decisions' => [
            'path' => 'decisionsPath',
            'stub' => 'decisions.stub.md',
        ],
    ];

    public function __construct(
        private readonly Paths $paths,
        private readonly FeatureNameValidator $featureNameValidator = new FeatureNameValidator(),
        private readonly ContextFileResolver $resolver = new ContextFileResolver(),
        private readonly FeatureSpecDocumentNormalizer $featureSpecDocumentNormalizer = new FeatureSpecDocumentNormalizer(),
    ) {}

    /**
     * @return array{success:bool,feature:string,feature_valid:bool,created:list<string>,existing:list<string>,issues:list<array<string,mixed>>}
     */
    public function init(string $featureName): array
    {
        $featureName = FeatureNaming::canonical($featureName);

        $nameValidation = $this->featureNameValidator->validate($featureName);
        if (!$nameValidation->valid) {
            return [
                'success' => false,
                'feature' => $featureName,
                'feature_valid' => false,
                'created' => [],
                'existing' => [],
                'issues' => $this->issuesToArray($nameValidation->issues),
            ];
        }

        $paths = $this->targetPaths($featureName);
        $directory = $this->paths->join(dirname($paths['spec']));
        if (!is_dir($directory) && !mkdir($directory, 0777, true) && !is_dir($directory)) {
            throw new FoundryError(
                'CONTEXT_DIRECTORY_CREATE_FAILED',
                'filesystem',
                ['path' => dirname($paths['spec'])],
                'Unable to create feature context directory.',
            );
        }

        $created = [];
        $existing = [];

        foreach (self::FILES as $kind => $definition) {
            $relativePath = (string) ($paths[$kind] ?? '');
            $absolutePath = $this->paths->join($relativePath);

            if (is_file($absolutePath)) {
                $existing[] = $relativePath;

                continue;
            }

            if (file_exists($absolutePath)) {
                throw new FoundryError(
                    'CONTEXT_FILE_PATH_BLOCKED',
                    'filesystem',
                    ['path' => $relativePath],
                    'Context file path exists but is not a file.',
                );
            }

            $contents = $this->renderStub($definition['stub'], $featureName);
            if ($definition['stub'] === 'spec.stub.md') {
                $contents = $this->featureSpecDocumentNormalizer->normalize($contents);
            }

            if (file_put_contents($absolutePath, $contents) === false) {
                throw new FoundryError(
                    'CONTEXT_FILE_WRITE_FAILED',
                    'filesystem',
                    ['path' => $relativePath],
                    'Unable to write context file.',
                );
            }

            $created[] = $relativePath;
        }

        return [
            'success' => true,
            'feature' => $featureName,
            'feature_valid' => true,
            'created' => $created,
            'existing' => $existing,
            'issues' => [],
        ];
    }

    /**
     * @return array{spec:string,state:string,decisions:string}
     */
    private function targetPaths(string $featureName): array
    {
        $canonicalRoot = $this->paths->join('Features');
        if (is_dir($canonicalRoot)) {
            return $this->resolver->canonicalPaths($featureName);
        }

        return $this->resolver->legacyPaths($featureName);
    }

    private function renderStub(string $stub, string $featureName): string
    {
        $path = $this->paths->frameworkJoin('stubs/context/' . $stub);
        $contents = file_get_contents($path);
        if ($contents === false) {
            throw new FoundryError(
                'CONTEXT_STUB_MISSING',
                'filesystem',
                ['path' => 'stubs/context/' . $stub],
                'Context stub could not be read.',
            );
        }

        return str_replace('{{feature}}', $featureName, $contents);
    }

    /**
     * @param array<int,ValidationIssue> $issues
     * @return list<array<string,mixed>>
     */
    private function issuesToArray(array $issues): array
    {
        return array_values(array_map(
            static function (ValidationIssue $issue): array {
                $row = [
                    'code' => $issue->code,
                    'message' => $issue->message,
                    'file_path' => $issue->file_path,
                ];

                if ($issue->section !== null) {
                    $row['section'] = $issue->section;
                }

                return $row;
            },
            $issues,
        ));
    }
}
