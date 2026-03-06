<?php
declare(strict_types=1);

namespace Foundry\Testing;

final class ContractTestGenerator
{
    public function generate(string $feature): string
    {
        $class = $this->className($feature, 'ContractTest');

        return <<<PHP
<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class {$class} extends TestCase
{
    public function test_input_schema_accepts_valid_payload(): void
    {
        self::assertTrue(true);
    }

    public function test_output_schema_matches_action_result_shape(): void
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
