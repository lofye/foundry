<?php

declare(strict_types=1);

namespace Vendor\BlogTools;

use Foundry\Packs\PackContext;
use Foundry\Packs\PackServiceProvider;

final class FoundryBlogToolsPackServiceProvider implements PackServiceProvider
{
    public function register(PackContext $context): void
    {
        $context->registerCommand('blog.sync');
        $context->registerSchema('blog.tools');
    }
}
