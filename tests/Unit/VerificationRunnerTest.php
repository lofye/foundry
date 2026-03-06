<?php
declare(strict_types=1);

namespace Forge\Tests\Unit;

use Forge\Verification\VerificationResult;
use Forge\Verification\VerificationRunner;
use PHPUnit\Framework\TestCase;

final class VerificationRunnerTest extends TestCase
{
    public function test_aggregate_combines_results(): void
    {
        $runner = new VerificationRunner();
        $result = $runner->aggregate([
            'a' => new VerificationResult(true, [], []),
            'b' => new VerificationResult(false, ['err'], ['warn']),
        ]);

        $this->assertFalse($result['ok']);
        $this->assertArrayHasKey('a', $result['results']);
        $this->assertArrayHasKey('b', $result['results']);
    }
}
