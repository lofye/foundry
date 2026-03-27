<?php
declare(strict_types=1);

namespace Foundry\Tests\Unit;

use Foundry\Pro\Generation\AIConfigLoader;
use Foundry\Support\FoundryError;
use Foundry\Support\Paths;
use Foundry\Tests\Fixtures\TempProject;
use PHPUnit\Framework\TestCase;

final class AIConfigLoaderTest extends TestCase
{
    private TempProject $project;

    protected function setUp(): void
    {
        $this->project = new TempProject();
    }

    protected function tearDown(): void
    {
        $this->project->cleanup();
    }

    public function test_load_returns_static_default_when_ai_config_is_missing(): void
    {
        $config = (new AIConfigLoader())->load(Paths::fromCwd($this->project->root));

        $this->assertSame('static', $config['default']);
    }

    public function test_load_normalizes_legacy_default_provider_key(): void
    {
        mkdir($this->project->root . '/config', 0777, true);
        file_put_contents($this->project->root . '/config/ai.php', <<<'PHP'
<?php
declare(strict_types=1);

return [
    'default_provider' => 'fixture',
    'providers' => [
        'fixture' => [
            'driver' => 'static',
        ],
    ],
];
PHP);

        $config = (new AIConfigLoader())->load(Paths::fromCwd($this->project->root));

        $this->assertSame('fixture', $config['default']);
        $this->assertSame('static', $config['providers']['fixture']['driver']);
    }

    public function test_load_rejects_non_array_config_payloads(): void
    {
        mkdir($this->project->root . '/config', 0777, true);
        file_put_contents($this->project->root . '/config/ai.php', <<<'PHP'
<?php
declare(strict_types=1);

return 'invalid';
PHP);

        try {
            (new AIConfigLoader())->load(Paths::fromCwd($this->project->root));
            self::fail('Expected invalid AI config failure.');
        } catch (FoundryError $error) {
            $this->assertSame('AI_CONFIG_INVALID', $error->errorCode);
        }
    }
}
