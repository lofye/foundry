<?php
declare(strict_types=1);

namespace Foundry\Tests\Unit;

use Foundry\Compiler\Extensions\AbstractCompilerExtension;
use Foundry\Compiler\Extensions\ExtensionDescriptor;
use Foundry\Compiler\Extensions\ExtensionMetadataValidator;
use Foundry\Compiler\Extensions\PackDefinition;
use PHPUnit\Framework\TestCase;

final class ExtensionMetadataValidatorTest extends TestCase
{
    public function test_validator_reports_invalid_extension_and_pack_metadata(): void
    {
        $extension = new class extends AbstractCompilerExtension {
            public function name(): string { return 'Bad Name'; }
            public function version(): string { return 'not-a-version'; }
            public function descriptor(): ExtensionDescriptor
            {
                return new ExtensionDescriptor(
                    name: $this->name(),
                    version: $this->version(),
                    frameworkVersionConstraint: 'definitely-not-a-constraint',
                    graphVersionConstraint: 'bad constraint',
                    requiredExtensions: ['Bad Dependency'],
                    conflictsWithExtensions: ['Bad Name'],
                );
            }
            public function packs(): array
            {
                return [
                    new PackDefinition(
                        name: 'Bad Pack',
                        version: 'oops',
                        extension: 'wrong-owner',
                        dependencies: ['Bad Dependency'],
                        conflictsWith: ['Bad Pack'],
                        frameworkVersionConstraint: 'bad constraint',
                        graphVersionConstraint: 'also bad',
                    ),
                ];
            }
        };

        $validator = new ExtensionMetadataValidator();
        $diagnostics = $validator->validateExtension($extension);
        $codes = array_values(array_map(static fn (array $row): string => (string) ($row['code'] ?? ''), $diagnostics));

        $this->assertContains('FDY7016_EXTENSION_METADATA_INVALID', $codes);
        $this->assertContains('FDY7017_PACK_METADATA_INVALID', $codes);
        $this->assertNotEmpty(array_filter(
            $diagnostics,
            static fn (array $row): bool => (string) (($row['details'] ?? [])['field'] ?? '') === 'name',
        ));
    }
}
