<?php

declare(strict_types=1);

namespace Foundry\Core;

enum RuntimeMode: string
{
    case Http = 'http';
    case Cli = 'cli';
    case Worker = 'worker';
    case Test = 'test';
}
