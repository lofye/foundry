<?php

declare(strict_types=1);

namespace Vendor\Blog;

use Foundry\Packs\PackContext;
use Foundry\Packs\PackServiceProvider;

final class FoundryBlogPackServiceProvider implements PackServiceProvider
{
    public function register(PackContext $context): void
    {
        $context->registerCommand('blog.sync');
        $context->registerSchema('blog.post');
        $context->registerGenerator('generate blog-post');
        $context->registerExtension(new FoundryBlogExtension($context->installPath()));
    }
}
