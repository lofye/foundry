<?php
declare(strict_types=1);

namespace Foundry\Support;

final class CliCommandPrefix
{
    public static function foundry(Paths $paths): string
    {
        if ($paths->root() === $paths->frameworkRoot()) {
            return 'php bin/foundry';
        }

        return is_file($paths->join('foundry'))
            ? 'foundry'
            : 'php vendor/bin/foundry';
    }
}
