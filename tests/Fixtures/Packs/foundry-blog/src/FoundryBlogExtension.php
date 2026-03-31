<?php

declare(strict_types=1);

namespace Vendor\Blog;

use Foundry\Compiler\Extensions\AbstractCompilerExtension;
use Foundry\Compiler\Extensions\ExtensionDescriptor;

final class FoundryBlogExtension extends AbstractCompilerExtension
{
    public function __construct(private readonly string $installPath) {}

    public function name(): string
    {
        return 'vendor.blog.fixture';
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
            description: 'Fixture extension for foundry/blog.',
            frameworkVersionConstraint: '*',
            graphVersionConstraint: '^2',
            requiredExtensions: ['core'],
        );
    }

    public function linkPasses(): array
    {
        return [new FoundryBlogInterceptorPass($this->installPath . '/foundry.json', 'interceptor:pack.foundry.blog')];
    }
}
