<?php
declare(strict_types=1);

namespace Foundry\Tests\Unit;

use Foundry\Config\ConfigRepository;
use Foundry\Config\EnvLoader;
use Foundry\Core\Environment;
use Foundry\Core\Kernel;
use Foundry\Core\RuntimeMode;
use Foundry\Http\RequestContext;
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
        $file = sys_get_temp_dir() . '/foundry-env-' . bin2hex(random_bytes(3));
        file_put_contents($file, "APP_ENV=local\n# comment\nAPP_DEBUG=true\n");

        $vars = (new EnvLoader())->load($file);
        $this->assertSame('local', $vars['APP_ENV']);
        $this->assertSame('true', $vars['APP_DEBUG']);

        @unlink($file);
    }

    public function test_env_loader_returns_empty_array_for_missing_file(): void
    {
        $vars = (new EnvLoader())->load('/missing/' . bin2hex(random_bytes(3)) . '.env');
        $this->assertSame([], $vars);
    }

    public function test_env_loader_ignores_malformed_lines_and_trims_quotes(): void
    {
        $file = sys_get_temp_dir() . '/foundry-env-' . bin2hex(random_bytes(3));
        file_put_contents($file, "MALFORMED_LINE\nAPP_NAME='Foundry'\nAPP_DESC=\"Framework\"\n");

        $vars = (new EnvLoader())->load($file);
        $this->assertArrayNotHasKey('MALFORMED_LINE', $vars);
        $this->assertSame('Foundry', $vars['APP_NAME']);
        $this->assertSame('Framework', $vars['APP_DESC']);

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

    public function test_kernel_returns_mode_error_when_not_in_http_mode(): void
    {
        $kernel = new Kernel(RuntimeMode::Cli, null);
        $response = $kernel->handleHttp(new RequestContext('GET', '/'));

        $this->assertSame(500, $response['status']);
        $this->assertSame('KERNEL_MODE_ERROR', $response['body']['error']['code']);
    }
}
