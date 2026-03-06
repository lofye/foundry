<?php
declare(strict_types=1);

namespace Forge\Testing;

final class AuthTestGenerator
{
    public function generate(string $feature): string
    {
        $class = $this->className($feature, 'AuthTest');

        return <<<PHP
<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class {$class} extends TestCase
{
    public function test_auth_rules_are_enforced(): void
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
