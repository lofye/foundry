<?php

declare(strict_types=1);

namespace Vendor\BlogDuplicate;

use Foundry\Compiler\Extensions\AbstractCompilerExtension;
use Foundry\Compiler\Extensions\ExtensionDescriptor;

final class FoundryBlogDuplicateExtension extends AbstractCompilerExtension
{
    public function __construct(private readonly string $installPath) {}

    public function name(): string
    {
        return 'vendor.blog.duplicate.fixture';
    }

    public function version(): string
    {
        return '1.0.0';
    }

    public function descriptor(): ExtensionDescriptor
    {
        return new ExtensionDescriptor(
            name: $this->name(),
            version: $this->version(),
            description: 'Fixture extension for duplicate graph node detection.',
            frameworkVersionConstraint: '*',
            graphVersionConstraint: '^2',
            requiredExtensions: ['core'],
        );
    }

    public function linkPasses(): array
    {
        return [new FoundryBlogDuplicateInterceptorPass($this->installPath . '/foundry.json')];
    }
}
