<?php
declare(strict_types=1);

namespace Foundry\Testing;

final class FeatureTestGenerator
{
    public function generate(string $feature): string
    {
        $class = $this->className($feature, 'FeatureTest');

        return <<<PHP
<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class {$class} extends TestCase
{
    public function test_feature_executes_successfully(): void
    {
        self::assertTrue(true);
    }
}
PHP;
    }

    private function className(string $feature, string $suffix): string
    {
        return str_replace(' ', '', ucwords(str_replace('_', ' ', $feature))) . $suffix;
    }
}
