<?php

declare(strict_types=1);

namespace Foundry\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class CompilerIrProxyFilesTest extends TestCase
{
    public function test_ir_proxy_files_remain_loadable_for_per_class_autoloading(): void
    {
        $irDirectory = dirname(__DIR__, 2) . '/src/Compiler/IR';
        $files = glob($irDirectory . '/*.php') ?: [];
        sort($files);

        $loaded = [];
        foreach ($files as $file) {
            if (basename($file) === 'Nodes.php') {
                continue;
            }

            require_once $file;
            $loaded[] = basename($file);
        }

        $this->assertContains('AbstractNode.php', $loaded);
        $this->assertContains('NodeFactory.php', $loaded);
        $this->assertContains('WorkflowNode.php', $loaded);
        $this->assertGreaterThanOrEqual(35, count($loaded));
    }
}
