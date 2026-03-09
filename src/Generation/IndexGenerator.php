<?php
declare(strict_types=1);

namespace Foundry\Generation;

use Foundry\Compiler\CompileOptions;
use Foundry\Compiler\GraphCompiler;
use Foundry\Support\Paths;

final class IndexGenerator
{
    public function __construct(
        private readonly Paths $paths,
        private readonly ?GraphCompiler $compiler = null,
    ) {
    }

    /**
     * @return array<int,string>
     */
    public function generate(): array
    {
        $compiler = $this->compiler ?? new GraphCompiler($this->paths);
        $compiler->compile(new CompileOptions());

        $files = [];
        foreach ([
            'routes.php',
            'feature_index.php',
            'schema_index.php',
            'permission_index.php',
            'event_index.php',
            'job_index.php',
            'cache_index.php',
            'scheduler_index.php',
            'webhook_index.php',
        ] as $file) {
            $path = $this->paths->join('app/generated/' . $file);
            if (is_file($path)) {
                $files[] = $path;
            }
        }

        return $files;
    }
}
