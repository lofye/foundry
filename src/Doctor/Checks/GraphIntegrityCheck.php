<?php
declare(strict_types=1);

namespace Foundry\Doctor\Checks;

use Foundry\Compiler\Diagnostics\DiagnosticBag;
use Foundry\Doctor\DoctorCheck;
use Foundry\Doctor\DoctorContext;
use Foundry\Doctor\DoctorSummary;
use Foundry\Support\Json;

final class GraphIntegrityCheck implements DoctorCheck
{
    public function id(): string
    {
        return 'graph_integrity';
    }

    public function description(): string
    {
        return 'Verifies emitted build artifacts, projection files, and integrity hashes.';
    }

    public function check(DoctorContext $context, DiagnosticBag $diagnostics): array
    {
        $artifacts = $this->artifactRows($context);
        $checkedJsonArtifacts = [];

        foreach ($artifacts as $artifact) {
            $absolutePath = (string) ($artifact['absolute_path'] ?? '');
            $relativePath = (string) ($artifact['path'] ?? '');
            if ($absolutePath === '' || $relativePath === '') {
                continue;
            }

            if (!is_file($absolutePath)) {
                $diagnostics->error(
                    code: 'FDY9109_BUILD_ARTIFACT_MISSING',
                    category: 'build',
                    message: 'Required build artifact is missing: ' . $relativePath . '.',
                    sourcePath: $relativePath,
                    suggestedFix: 'Rebuild the graph and restore generated build artifacts.',
                    pass: 'doctor.graph_integrity',
                    whyItMatters: 'Foundry runtime execution and inspection surfaces depend on these emitted build artifacts.',
                    details: ['artifact' => $relativePath, 'kind' => (string) ($artifact['kind'] ?? '')],
                );
                continue;
            }

            if (!(bool) ($artifact['json'] ?? false)) {
                continue;
            }

            $json = file_get_contents($absolutePath);
            if ($json === false) {
                $diagnostics->error(
                    code: 'FDY9110_BUILD_ARTIFACT_INVALID',
                    category: 'build',
                    message: 'Build artifact could not be read: ' . $relativePath . '.',
                    sourcePath: $relativePath,
                    suggestedFix: 'Rebuild the graph so the artifact is regenerated.',
                    pass: 'doctor.graph_integrity',
                    whyItMatters: 'Unreadable build metadata prevents Foundry from validating graph state and tool outputs.',
                    details: ['artifact' => $relativePath],
                );
                continue;
            }

            try {
                Json::decodeAssoc($json);
                $checkedJsonArtifacts[] = $relativePath;
            } catch (\Throwable $error) {
                $diagnostics->error(
                    code: 'FDY9110_BUILD_ARTIFACT_INVALID',
                    category: 'build',
                    message: 'Build artifact contains invalid JSON: ' . $relativePath . '.',
                    sourcePath: $relativePath,
                    suggestedFix: 'Rebuild the graph so the artifact is regenerated with valid JSON.',
                    pass: 'doctor.graph_integrity',
                    whyItMatters: 'Invalid build metadata makes machine-readable diagnostics and graph inspection unreliable.',
                    details: [
                        'artifact' => $relativePath,
                        'exception' => $error::class,
                    ],
                );
            }
        }

        foreach ($context->compileResult->integrityHashes as $relativePath => $expectedHash) {
            $absolutePath = $context->paths->join($relativePath);
            if (!is_file($absolutePath)) {
                $diagnostics->warning(
                    code: 'FDY9111_BUILD_ARTIFACT_REFERENCED_BY_INTEGRITY_MISSING',
                    category: 'build',
                    message: 'Integrity manifest references a missing artifact: ' . $relativePath . '.',
                    sourcePath: $relativePath,
                    suggestedFix: 'Recompile the graph so integrity hashes are regenerated against current artifacts.',
                    pass: 'doctor.graph_integrity',
                    whyItMatters: 'Integrity metadata should describe the emitted build set exactly; missing files usually mean the build output is incomplete.',
                    details: ['artifact' => $relativePath],
                );
                continue;
            }

            $actualHash = hash_file('sha256', $absolutePath);
            if ($actualHash === false || $actualHash === $expectedHash) {
                continue;
            }

            $diagnostics->warning(
                code: 'FDY9112_BUILD_ARTIFACT_HASH_MISMATCH',
                category: 'build',
                message: 'Integrity hash mismatch detected for artifact: ' . $relativePath . '.',
                sourcePath: $relativePath,
                suggestedFix: 'Rebuild the graph and avoid hand-editing generated artifacts.',
                pass: 'doctor.graph_integrity',
                whyItMatters: 'Hash mismatches usually mean generated artifacts were edited outside the compiler, which can desynchronize runtime behavior from source-of-truth inputs.',
                details: [
                    'artifact' => $relativePath,
                    'expected_hash' => $expectedHash,
                    'actual_hash' => $actualHash,
                ],
            );
        }

        $summary = $diagnostics->summary();

        return [
            'status' => DoctorSummary::status($summary),
            'diagnostics_summary' => $summary,
            'artifact_count' => count($artifacts),
            'integrity_hash_count' => count($context->compileResult->integrityHashes),
            'json_artifacts_checked' => $checkedJsonArtifacts,
        ];
    }

    /**
     * @return array<int,array{path:string,absolute_path:string,kind:string,json:bool}>
     */
    private function artifactRows(DoctorContext $context): array
    {
        $rows = [
            [
                'path' => $context->relativePath($context->layout->graphJsonPath()),
                'absolute_path' => $context->layout->graphJsonPath(),
                'kind' => 'graph_json',
                'json' => true,
            ],
            [
                'path' => $context->relativePath($context->layout->graphPhpPath()),
                'absolute_path' => $context->layout->graphPhpPath(),
                'kind' => 'graph_php',
                'json' => false,
            ],
            [
                'path' => $context->relativePath($context->layout->compileManifestPath()),
                'absolute_path' => $context->layout->compileManifestPath(),
                'kind' => 'compile_manifest',
                'json' => true,
            ],
            [
                'path' => $context->relativePath($context->layout->integrityHashesPath()),
                'absolute_path' => $context->layout->integrityHashesPath(),
                'kind' => 'integrity_hashes',
                'json' => true,
            ],
            [
                'path' => $context->relativePath($context->layout->diagnosticsPath()),
                'absolute_path' => $context->layout->diagnosticsPath(),
                'kind' => 'diagnostics',
                'json' => true,
            ],
        ];

        foreach ($context->compileResult->projections as $projection) {
            if (!is_array($projection)) {
                continue;
            }

            $file = trim((string) ($projection['file'] ?? ''));
            if ($file !== '') {
                $absolutePath = $context->layout->projectionPath($file);
                $rows[] = [
                    'path' => $context->relativePath($absolutePath),
                    'absolute_path' => $absolutePath,
                    'kind' => 'projection',
                    'json' => false,
                ];
            }

            $legacyFile = trim((string) ($projection['legacy_file'] ?? ''));
            if ($legacyFile !== '') {
                $absolutePath = $context->layout->legacyProjectionPath($legacyFile);
                $rows[] = [
                    'path' => $context->relativePath($absolutePath),
                    'absolute_path' => $absolutePath,
                    'kind' => 'legacy_projection',
                    'json' => false,
                ];
            }
        }

        $unique = [];
        foreach ($rows as $row) {
            $unique[$row['path']] = $row;
        }
        ksort($unique);

        return array_values($unique);
    }
}
