<?php

declare(strict_types=1);

namespace Vendor\BlogDuplicate;

use Foundry\Packs\PackContext;
use Foundry\Packs\PackServiceProvider;

final class FoundryBlogDuplicatePackServiceProvider implements PackServiceProvider
{
    public function register(PackContext $context): void
    {
        $context->registerExtension(new FoundryBlogDuplicateExtension($context->installPath()));
    }
}
