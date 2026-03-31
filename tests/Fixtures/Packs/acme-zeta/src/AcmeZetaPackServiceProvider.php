<?php

declare(strict_types=1);

namespace Acme\Zeta;

use Foundry\Packs\PackContext;
use Foundry\Packs\PackServiceProvider;

final class AcmeZetaPackServiceProvider implements PackServiceProvider
{
    public function register(PackContext $context): void
    {
        $context->registerCommand('zeta.sync');
        $context->registerSchema('zeta.document');
    }
}
