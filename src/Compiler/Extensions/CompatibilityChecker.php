<?php

declare(strict_types=1);

namespace Foundry\Compiler\Extensions;

use Foundry\Compiler\Migration\DefinitionFormat;

final class CompatibilityChecker
{
    public function __construct(
        private readonly ExtensionRegistry $extensions,
        private readonly PackRegistry $packs,
    ) {}

    public function check(string $frameworkVersion, int $graphVersion): CompatibilityReport
    {
        $diagnostics = $this->extensions->diagnostics();
        $loadedExtensions = $this->extensions->loadedExtensions();
        $loadedPacks = $this->extensions->loadedPacks();

        $nodeTypeOwners = [];
        $projectionOwners = [];

        foreach ($loadedExtensions as $extension) {
            $descriptor = $extension->descriptor();

            if (!VersionConstraint::matches($frameworkVersion, $descriptor->frameworkVersionConstraint)) {
                $diagnostics[] = $this->diagnostic(
                    code: 'FDY7001_INCOMPATIBLE_EXTENSION_VERSION',
                    severity: 'error',
                    message: sprintf(
                        'Extension %s@%s is incompatible with framework version %s (constraint %s).',
                        $descriptor->name,
                        $descriptor->version,
                        $frameworkVersion,
                        $descriptor->frameworkVersionConstraint,
                    ),
                    extension: $descriptor->name,
                );
            }

            if (!VersionConstraint::matches((string) $graphVersion, $descriptor->graphVersionConstraint)) {
                $diagnostics[] = $this->diagnostic(
                    code: 'FDY7002_INCOMPATIBLE_GRAPH_VERSION',
                    severity: 'error',
                    message: sprintf(
                        'Extension %s@%s is incompatible with graph version %d (constraint %s).',
                        $descriptor->name,
                        $descriptor->version,
                        $graphVersion,
                        $descriptor->graphVersionConstraint,
                    ),
                    extension: $descriptor->name,
                );
            }

            foreach ($descriptor->providedNodeTypes as $nodeType) {
                $nodeType = (string) $nodeType;
                if ($nodeType === '') {
                    continue;
                }
                $nodeTypeOwners[$nodeType] ??= [];
                $nodeTypeOwners[$nodeType][] = $descriptor->name;
            }

            foreach ($descriptor->providedProjectionOutputs as $projectionFile) {
                $projectionFile = (string) $projectionFile;
                if ($projectionFile === '') {
                    continue;
                }
                $projectionOwners[$projectionFile] ??= [];
                $projectionOwners[$projectionFile][] = $descriptor->name;
            }
        }

        foreach ($nodeTypeOwners as $nodeType => $owners) {
            $owners = array_values(array_unique(array_map('strval', $owners)));
            if (count($owners) <= 1) {
                continue;
            }

            sort($owners);
            $diagnostics[] = $this->diagnostic(
                code: 'FDY7006_CONFLICTING_NODE_PROVIDER',
                severity: 'error',
                message: sprintf('Node type %s is provided by multiple extensions (%s).', $nodeType, implode(', ', $owners)),
            );
        }

        foreach ($projectionOwners as $projection => $owners) {
            $owners = array_values(array_unique(array_map('strval', $owners)));
            if (count($owners) <= 1) {
                continue;
            }

            sort($owners);
            $diagnostics[] = $this->diagnostic(
                code: 'FDY7007_CONFLICTING_PROJECTION_PROVIDER',
                severity: 'error',
                message: sprintf('Projection %s is emitted by multiple extensions (%s).', $projection, implode(', ', $owners)),
            );
        }

        $capabilities = $this->providedCapabilities($loadedPacks);
        foreach ($loadedPacks as $pack) {
            if (!VersionConstraint::matches($frameworkVersion, $pack->frameworkVersionConstraint)) {
                $diagnostics[] = $this->diagnostic(
                    code: 'FDY7008_INCOMPATIBLE_PACK_VERSION',
                    severity: 'error',
                    message: sprintf(
                        'Pack %s@%s is incompatible with framework version %s (constraint %s).',
                        $pack->name,
                        $pack->version,
                        $frameworkVersion,
                        $pack->frameworkVersionConstraint,
                    ),
                    pack: $pack->name,
                );
            }

            if (!VersionConstraint::matches((string) $graphVersion, $pack->graphVersionConstraint)) {
                $diagnostics[] = $this->diagnostic(
                    code: 'FDY7002_INCOMPATIBLE_GRAPH_VERSION',
                    severity: 'error',
                    message: sprintf(
                        'Pack %s@%s is incompatible with graph version %d (constraint %s).',
                        $pack->name,
                        $pack->version,
                        $graphVersion,
                        $pack->graphVersionConstraint,
                    ),
                    pack: $pack->name,
                );
            }

            foreach ($pack->requiredCapabilities as $requiredCapability) {
                $requiredCapability = (string) $requiredCapability;
                if ($requiredCapability === '') {
                    continue;
                }
                if (in_array($requiredCapability, $capabilities, true)) {
                    continue;
                }

                $diagnostics[] = $this->diagnostic(
                    code: 'FDY7009_PACK_CAPABILITY_MISSING',
                    severity: 'error',
                    message: sprintf('Pack %s requires missing capability %s.', $pack->name, $requiredCapability),
                    pack: $pack->name,
                );
            }
        }

        $definitionFormatVersions = [];
        foreach ($this->definitionFormats($loadedExtensions) as $format) {
            if (!$format instanceof DefinitionFormat) {
                continue;
            }

            $existing = $definitionFormatVersions[$format->name] ?? null;
            if ($existing !== null && $existing !== $format->currentVersion) {
                $diagnostics[] = $this->diagnostic(
                    code: 'FDY7003_UNSUPPORTED_DEFINITION_VERSION',
                    severity: 'error',
                    message: sprintf(
                        'Definition format %s is declared with conflicting current versions (%d and %d).',
                        $format->name,
                        (int) $existing,
                        $format->currentVersion,
                    ),
                );
            }
            $definitionFormatVersions[$format->name] = $format->currentVersion;
        }
        ksort($definitionFormatVersions);

        usort(
            $diagnostics,
            static fn(array $a, array $b): int => strcmp((string) ($a['code'] ?? ''), (string) ($b['code'] ?? ''))
                ?: strcmp((string) ($a['message'] ?? ''), (string) ($b['message'] ?? '')),
        );

        $hasErrors = false;
        foreach ($diagnostics as $diagnostic) {
            if ((string) ($diagnostic['severity'] ?? '') === 'error') {
                $hasErrors = true;
                break;
            }
        }

        return new CompatibilityReport(
            ok: !$hasErrors,
            diagnostics: $this->sortDiagnostics($diagnostics),
            versionMatrix: [
                'framework_version' => $frameworkVersion,
                'graph_version' => $graphVersion,
                'extension_versions' => array_values(array_map(
                    static fn(CompilerExtension $extension): array => [
                        'name' => $extension->name(),
                        'version' => $extension->version(),
                    ],
                    $loadedExtensions,
                )),
                'pack_versions' => array_values(array_map(
                    static fn(PackDefinition $pack): array => [
                        'name' => $pack->name,
                        'version' => $pack->version,
                        'extension' => $pack->extension,
                    ],
                    $loadedPacks,
                )),
                'definition_versions' => $definitionFormatVersions,
            ],
            lifecycle: $this->extensions->lifecycleRows(),
            loadOrder: $this->extensions->loadOrder(),
        );
    }

    /**
     * @return array<string,mixed>
     */
    private function diagnostic(
        string $code,
        string $severity,
        string $message,
        ?string $extension = null,
        ?string $pack = null,
    ): array {
        return [
            'code' => $code,
            'severity' => $severity,
            'category' => 'extensions',
            'message' => $message,
            'extension' => $extension,
            'pack' => $pack,
        ];
    }

    /**
     * @param array<int,PackDefinition> $packs
     * @return array<int,string>
     */
    private function providedCapabilities(array $packs): array
    {
        $capabilities = [];
        foreach ($packs as $pack) {
            foreach ($pack->providedCapabilities as $capability) {
                $capability = trim((string) $capability);
                if ($capability !== '') {
                    $capabilities[] = $capability;
                }
            }
        }

        $capabilities = array_values(array_unique($capabilities));
        sort($capabilities);

        return $capabilities;
    }

    /**
     * @param array<int,CompilerExtension> $extensions
     * @return array<int,DefinitionFormat>
     */
    private function definitionFormats(array $extensions): array
    {
        $formats = [];
        foreach ($extensions as $extension) {
            foreach ($extension->definitionFormats() as $format) {
                $formats[] = $format;
            }
        }

        usort($formats, static fn(DefinitionFormat $a, DefinitionFormat $b): int => strcmp($a->name, $b->name));

        return $formats;
    }

    /**
     * @param array<int,array<string,mixed>> $diagnostics
     * @return array<int,array<string,mixed>>
     */
    private function sortDiagnostics(array $diagnostics): array
    {
        $unique = [];
        foreach ($diagnostics as $diagnostic) {
            if (!is_array($diagnostic)) {
                continue;
            }

            $unique[md5(json_encode($diagnostic, JSON_THROW_ON_ERROR))] = $diagnostic;
        }

        $diagnostics = array_values($unique);
        usort(
            $diagnostics,
            static fn(array $a, array $b): int => strcmp((string) ($a['code'] ?? ''), (string) ($b['code'] ?? ''))
                ?: strcmp((string) ($a['extension'] ?? ''), (string) ($b['extension'] ?? ''))
                ?: strcmp((string) ($a['pack'] ?? ''), (string) ($b['pack'] ?? ''))
                ?: strcmp((string) ($a['message'] ?? ''), (string) ($b['message'] ?? '')),
        );

        return $diagnostics;
    }
}
