<?php

declare(strict_types=1);

namespace Vendor\Blog;

use Foundry\Compiler\CompilationState;
use Foundry\Compiler\CompilerPass;
use Foundry\Compiler\IR\InterceptorNode;

final class FoundryBlogInterceptorPass implements CompilerPass
{
    public function __construct(
        private readonly string $sourcePath,
        private readonly string $nodeId,
    ) {}

    public function name(): string
    {
        return 'vendor.blog.interceptor';
    }

    public function run(CompilationState $state): void
    {
        $state->graph->addNode(new InterceptorNode(
            $this->nodeId,
            $this->sourcePath,
            ['id' => 'pack.foundry.blog', 'stage' => 'auth'],
        ));
    }
}
