<?php
declare(strict_types=1);

namespace Foundry\Tests\Fixtures;

use Foundry\Compiler\Extensions\AbstractCompilerExtension;
use Foundry\Compiler\Extensions\ExtensionDescriptor;
use Foundry\Compiler\Extensions\PackDefinition;

final class CustomUpgradeExtension extends AbstractCompilerExtension
{
    public function name(): string
    {
        return 'tests.custom_upgrade';
    }

    public function version(): string
    {
        return '0.4.0';
    }

    public function descriptor(): ExtensionDescriptor
    {
        return new ExtensionDescriptor(
            name: $this->name(),
            version: $this->version(),
            description: 'Fixture extension used by upgrade-check tests.',
            frameworkVersionConstraint: '^0.4',
            graphVersionConstraint: '^1',
            providedPacks: ['tests.custom_upgrade_pack'],
        );
    }

    public function packs(): array
    {
        return [
            new PackDefinition(
                name: 'tests.custom_upgrade_pack',
                version: $this->version(),
                extension: $this->name(),
                description: 'Fixture pack used by upgrade-check tests.',
                frameworkVersionConstraint: '^0.4',
                graphVersionConstraint: '^1',
            ),
        ];
    }
}
