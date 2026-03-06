<?php
declare(strict_types=1);

namespace Foundry\CLI;

abstract class Command
{
    /**
     * @param array<int,string> $args
     */
    abstract public function matches(array $args): bool;

    /**
     * @param array<int,string> $args
     * @return array{status:int,payload:array<string,mixed>|null,message:string|null}
     */
    abstract public function run(array $args, CommandContext $context): array;
}
