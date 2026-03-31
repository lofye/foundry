<?php

declare(strict_types=1);

namespace Foundry\Compiler\Extensions;

final class ExtensionMetadataValidator
{
    /**
     * @return array<int,array<string,mixed>>
     */
    public function validateExtension(CompilerExtension $extension): array
    {
        $diagnostics = [];
        $descriptor = $extension->descriptor();

        $diagnostics = array_merge($diagnostics, $this->validateDescriptor($descriptor));

        foreach ($extension->packs() as $pack) {
            if (!$pack instanceof PackDefinition) {
                continue;
            }

            $diagnostics = array_merge($diagnostics, $this->validatePack($pack, $descriptor));
        }

        usort(
            $diagnostics,
            static fn(array $a, array $b): int => strcmp((string) ($a['code'] ?? ''), (string) ($b['code'] ?? ''))
                ?: strcmp((string) ($a['message'] ?? ''), (string) ($b['message'] ?? '')),
        );

        return $diagnostics;
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function validateDescriptor(ExtensionDescriptor $descriptor): array
    {
        $diagnostics = [];

        if (!$this->isValidIdentifier($descriptor->name)) {
            $diagnostics[] = $this->diagnostic(
                code: 'FDY7016_EXTENSION_METADATA_INVALID',
                message: sprintf('Extension %s has invalid metadata: name must be a stable lowercase identifier.', $descriptor->name !== '' ? $descriptor->name : '<unknown>'),
                extension: $descriptor->name,
                field: 'name',
            );
        }

        if (!$this->isValidVersion($descriptor->version)) {
            $diagnostics[] = $this->diagnostic(
                code: 'FDY7016_EXTENSION_METADATA_INVALID',
                message: sprintf('Extension %s has invalid metadata: version must be a semantic version or dev-* tag.', $descriptor->name !== '' ? $descriptor->name : '<unknown>'),
                extension: $descriptor->name,
                field: 'version',
            );
        }

        if (!$this->isValidConstraint($descriptor->frameworkVersionConstraint)) {
            $diagnostics[] = $this->diagnostic(
                code: 'FDY7016_EXTENSION_METADATA_INVALID',
                message: sprintf('Extension %s has invalid metadata: framework_version_constraint is not a supported version constraint.', $descriptor->name),
                extension: $descriptor->name,
                field: 'framework_version_constraint',
            );
        }

        if (!$this->isValidConstraint($descriptor->graphVersionConstraint)) {
            $diagnostics[] = $this->diagnostic(
                code: 'FDY7016_EXTENSION_METADATA_INVALID',
                message: sprintf('Extension %s has invalid metadata: graph_version_constraint is not a supported version constraint.', $descriptor->name),
                extension: $descriptor->name,
                field: 'graph_version_constraint',
            );
        }

        $diagnostics = array_merge($diagnostics, $this->validateIdentifierList(
            extension: $descriptor->name,
            field: 'required_extensions',
            values: $descriptor->requiredExtensions,
            code: 'FDY7016_EXTENSION_METADATA_INVALID',
        ));

        $diagnostics = array_merge($diagnostics, $this->validateIdentifierList(
            extension: $descriptor->name,
            field: 'optional_extensions',
            values: $descriptor->optionalExtensions,
            code: 'FDY7016_EXTENSION_METADATA_INVALID',
        ));

        $diagnostics = array_merge($diagnostics, $this->validateIdentifierList(
            extension: $descriptor->name,
            field: 'conflicts_with_extensions',
            values: $descriptor->conflictsWithExtensions,
            code: 'FDY7016_EXTENSION_METADATA_INVALID',
        ));

        foreach ([
            'required_extensions' => $descriptor->requiredExtensions,
            'optional_extensions' => $descriptor->optionalExtensions,
            'conflicts_with_extensions' => $descriptor->conflictsWithExtensions,
        ] as $field => $values) {
            $clean = array_values(array_filter(array_map('strval', $values), static fn(string $value): bool => $value !== ''));
            if (count($clean) !== count(array_unique($clean))) {
                $diagnostics[] = $this->diagnostic(
                    code: 'FDY7016_EXTENSION_METADATA_INVALID',
                    message: sprintf('Extension %s has invalid metadata: %s contains duplicates.', $descriptor->name, $field),
                    extension: $descriptor->name,
                    field: $field,
                );
            }
        }

        foreach (array_merge($descriptor->requiredExtensions, $descriptor->optionalExtensions, $descriptor->conflictsWithExtensions) as $dependency) {
            if ((string) $dependency !== $descriptor->name) {
                continue;
            }

            $diagnostics[] = $this->diagnostic(
                code: 'FDY7016_EXTENSION_METADATA_INVALID',
                message: sprintf('Extension %s has invalid metadata: it cannot depend on or conflict with itself.', $descriptor->name),
                extension: $descriptor->name,
                field: 'dependencies',
            );
            break;
        }

        return $diagnostics;
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function validatePack(PackDefinition $pack, ExtensionDescriptor $descriptor): array
    {
        $diagnostics = [];

        if (!$this->isValidPackIdentifier($pack->name)) {
            $diagnostics[] = $this->diagnostic(
                code: 'FDY7017_PACK_METADATA_INVALID',
                message: sprintf('Pack %s has invalid metadata: name must be a stable pack identifier.', $pack->name !== '' ? $pack->name : '<unknown>'),
                extension: $descriptor->name,
                pack: $pack->name,
                field: 'name',
            );
        }

        if (!$this->isValidVersion($pack->version)) {
            $diagnostics[] = $this->diagnostic(
                code: 'FDY7017_PACK_METADATA_INVALID',
                message: sprintf('Pack %s has invalid metadata: version must be a semantic version or dev-* tag.', $pack->name),
                extension: $descriptor->name,
                pack: $pack->name,
                field: 'version',
            );
        }

        if ($pack->extension !== $descriptor->name) {
            $diagnostics[] = $this->diagnostic(
                code: 'FDY7017_PACK_METADATA_INVALID',
                message: sprintf('Pack %s declares owner %s, but the registering extension is %s.', $pack->name, $pack->extension, $descriptor->name),
                extension: $descriptor->name,
                pack: $pack->name,
                field: 'extension',
            );
        }

        if (!$this->isValidConstraint($pack->frameworkVersionConstraint)) {
            $diagnostics[] = $this->diagnostic(
                code: 'FDY7017_PACK_METADATA_INVALID',
                message: sprintf('Pack %s has invalid metadata: framework_version_constraint is not a supported version constraint.', $pack->name),
                extension: $descriptor->name,
                pack: $pack->name,
                field: 'framework_version_constraint',
            );
        }

        if (!$this->isValidConstraint($pack->graphVersionConstraint)) {
            $diagnostics[] = $this->diagnostic(
                code: 'FDY7017_PACK_METADATA_INVALID',
                message: sprintf('Pack %s has invalid metadata: graph_version_constraint is not a supported version constraint.', $pack->name),
                extension: $descriptor->name,
                pack: $pack->name,
                field: 'graph_version_constraint',
            );
        }

        foreach ([
            'dependencies' => $pack->dependencies,
            'optional_dependencies' => $pack->optionalDependencies,
            'conflicts_with' => $pack->conflictsWith,
        ] as $field => $values) {
            $diagnostics = array_merge($diagnostics, $this->validatePackIdentifierList(
                extension: $descriptor->name,
                field: $field,
                values: $values,
                code: 'FDY7017_PACK_METADATA_INVALID',
                pack: $pack->name,
            ));

            $clean = array_values(array_filter(array_map('strval', $values), static fn(string $value): bool => $value !== ''));
            if (count($clean) !== count(array_unique($clean))) {
                $diagnostics[] = $this->diagnostic(
                    code: 'FDY7017_PACK_METADATA_INVALID',
                    message: sprintf('Pack %s has invalid metadata: %s contains duplicates.', $pack->name, $field),
                    extension: $descriptor->name,
                    pack: $pack->name,
                    field: $field,
                );
            }
        }

        foreach (array_merge($pack->dependencies, $pack->optionalDependencies, $pack->conflictsWith) as $dependency) {
            if ((string) $dependency !== $pack->name) {
                continue;
            }

            $diagnostics[] = $this->diagnostic(
                code: 'FDY7017_PACK_METADATA_INVALID',
                message: sprintf('Pack %s has invalid metadata: it cannot depend on or conflict with itself.', $pack->name),
                extension: $descriptor->name,
                pack: $pack->name,
                field: 'dependencies',
            );
            break;
        }

        return $diagnostics;
    }

    /**
     * @param array<int,string> $values
     * @return array<int,array<string,mixed>>
     */
    private function validateIdentifierList(string $extension, string $field, array $values, string $code, ?string $pack = null): array
    {
        $diagnostics = [];

        foreach ($values as $value) {
            $candidate = trim((string) $value);
            if ($candidate === '') {
                continue;
            }

            if ($this->isValidIdentifier($candidate)) {
                continue;
            }

            $diagnostics[] = $this->diagnostic(
                code: $code,
                message: sprintf('%s %s has invalid metadata: %s contains unsupported identifier %s.', $pack !== null ? 'Pack' : 'Extension', $pack ?? $extension, $field, $candidate),
                extension: $extension,
                pack: $pack,
                field: $field,
            );
        }

        return $diagnostics;
    }

    /**
     * @param array<int,string> $values
     * @return array<int,array<string,mixed>>
     */
    private function validatePackIdentifierList(string $extension, string $field, array $values, string $code, ?string $pack = null): array
    {
        $diagnostics = [];

        foreach ($values as $value) {
            $candidate = trim((string) $value);
            if ($candidate === '') {
                continue;
            }

            if ($this->isValidPackIdentifier($candidate)) {
                continue;
            }

            $diagnostics[] = $this->diagnostic(
                code: $code,
                message: sprintf('Pack %s has invalid metadata: %s contains unsupported identifier %s.', $pack ?? $extension, $field, $candidate),
                extension: $extension,
                pack: $pack,
                field: $field,
            );
        }

        return $diagnostics;
    }

    private function isValidIdentifier(string $value): bool
    {
        return $value !== '' && preg_match('/^[a-z0-9]+(?:[._-][a-z0-9]+)*$/', $value) === 1;
    }

    private function isValidPackIdentifier(string $value): bool
    {
        return $this->isValidIdentifier($value)
            || preg_match('/^[a-z0-9]+(?:[._-][a-z0-9]+)*\/[a-z0-9]+(?:[._-][a-z0-9]+)*$/', $value) === 1;
    }

    private function isValidVersion(string $value): bool
    {
        return $value !== '' && preg_match('/^(?:\d+\.\d+\.\d+(?:[-+][A-Za-z0-9.-]+)?|dev-[A-Za-z0-9.-]+)$/', $value) === 1;
    }

    private function isValidConstraint(string $value): bool
    {
        return $value === '*'
            || preg_match('/^\^\d+(?:\.\d+)?(?:\.\d+)?$/', $value) === 1
            || $this->isValidVersion($value);
    }

    /**
     * @return array<string,mixed>
     */
    private function diagnostic(string $code, string $message, string $extension, ?string $field = null, ?string $pack = null): array
    {
        return [
            'code' => $code,
            'severity' => 'error',
            'category' => 'extensions',
            'message' => $message,
            'extension' => $extension !== '' ? $extension : null,
            'pack' => $pack,
            'details' => array_filter([
                'field' => $field,
            ], static fn(mixed $value): bool => $value !== null && $value !== ''),
        ];
    }
}
