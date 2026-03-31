<?php

declare(strict_types=1);

namespace Vendor\BlogDuplicate;

use Foundry\Compiler\CompilationState;
use Foundry\Compiler\CompilerPass;
use Foundry\Compiler\IR\InterceptorNode;

final class FoundryBlogDuplicateInterceptorPass implements CompilerPass
{
    public function __construct(private readonly string $sourcePath) {}

    public function name(): string
    {
        return 'vendor.blog.duplicate.interceptor';
    }

    public function run(CompilationState $state): void
    {
        $state->graph->addNode(new InterceptorNode(
            'interceptor:pack.foundry.blog',
            $this->sourcePath,
            ['id' => 'pack.foundry.blog.duplicate', 'stage' => 'auth'],
        ));
    }
}
