<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class ContextPersistenceFeatureTest extends TestCase
{
    public function test_feature_manifest_declares_context_persistence_route(): void
    {
        $manifest = (string) file_get_contents(dirname(__DIR__) . '/feature.yaml');

        self::assertStringContainsString('feature: context-persistence', $manifest);
        self::assertStringContainsString('path: /context-persistence', $manifest);
        self::assertStringContainsString('risk_level: medium', $manifest);
    }
}
