<?php
declare(strict_types=1);

namespace Forge\Tests\Unit;

use Forge\Config\ConfigRepository;
use Forge\Config\EnvLoader;
use Forge\Core\Environment;
use Forge\Core\Kernel;
use Forge\Core\RuntimeMode;
use Forge\Http\RequestContext;
use PHPUnit\Framework\TestCase;

final class ConfigAndCoreTest extends TestCase
{
    public function test_config_repository_set_and_get(): void
    {
        $config = new ConfigRepository();
        $config->set('db.default', 'sqlite');

        $this->assertSame('sqlite', $config->get('db.default'));
    }

    public function test_env_loader_parses_dotenv_file(): void
    {
        $file = sys_get_temp_dir() . '/forge-env-' . bin2hex(random_bytes(3));
        file_put_contents($file, "APP_ENV=local\n# comment\nAPP_DEBUG=true\n");

        $vars = (new EnvLoader())->load($file);
        $this->assertSame('local', $vars['APP_ENV']);
        $this->assertSame('true', $vars['APP_DEBUG']);

        @unlink($file);
    }

    public function test_environment_var_lookup(): void
    {
        $env = new Environment('test', true, ['FOO' => 'bar']);
        $this->assertSame('bar', $env->var('FOO'));
        $this->assertTrue($env->isDebug());
    }

    public function test_kernel_returns_error_when_http_kernel_missing(): void
    {
        $kernel = new Kernel(RuntimeMode::Http, null);
        $response = $kernel->handleHttp(new RequestContext('GET', '/'));

        $this->assertSame(500, $response['status']);
    }
}
