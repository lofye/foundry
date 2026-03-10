<?php
declare(strict_types=1);

namespace Foundry\Generation;

use Foundry\Support\FoundryError;
use Foundry\Support\Paths;
use Foundry\Support\Yaml;

final class BillingGenerator
{
    public function __construct(
        private readonly Paths $paths,
        private readonly FeatureGenerator $features,
    ) {
    }

    /**
     * @return array<string,mixed>
     */
    public function generate(string $provider, bool $force = false): array
    {
        $provider = strtolower(trim($provider));
        if ($provider === '') {
            throw new FoundryError('BILLING_PROVIDER_REQUIRED', 'validation', [], 'Billing provider is required.');
        }

        if ($provider !== 'stripe') {
            throw new FoundryError('BILLING_PROVIDER_UNSUPPORTED', 'validation', ['provider' => $provider], 'Only stripe is currently supported.');
        }

        $dir = $this->paths->join('app/specs/billing');
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }

        $specPath = $dir . '/' . $provider . '.billing.yaml';
        if (is_file($specPath) && !$force) {
            throw new FoundryError('BILLING_SPEC_EXISTS', 'io', ['path' => $specPath], 'Billing spec already exists. Use --force to overwrite.');
        }

        $spec = [
            'version' => 1,
            'provider' => $provider,
            'plans' => [
                [
                    'key' => 'starter',
                    'display_name' => 'Starter',
                    'price_id' => 'price_starter',
                    'interval' => 'month',
                    'trial_days' => 14,
                ],
                [
                    'key' => 'pro',
                    'display_name' => 'Pro',
                    'price_id' => 'price_pro',
                    'interval' => 'month',
                    'trial_days' => 14,
                ],
            ],
            'feature_names' => [
                'checkout' => 'create_checkout_session',
                'portal' => 'view_billing_portal',
                'webhook' => 'handle_billing_webhook',
                'invoices' => 'list_invoices',
                'subscription' => 'view_current_subscription',
            ],
            'webhook_signing_secret_env' => 'STRIPE_WEBHOOK_SECRET',
        ];
        file_put_contents($specPath, Yaml::dump($spec));

        $generated = [$specPath];
        foreach ($this->featureSpecs() as $featureSpec) {
            foreach ($this->features->generateFromArray($featureSpec, $force) as $file) {
                $generated[] = $file;
            }
        }

        $generated = array_values(array_unique($generated));
        sort($generated);

        return [
            'provider' => $provider,
            'spec' => $specPath,
            'features' => array_values(array_map(
                static fn (array $row): string => (string) ($row['feature'] ?? ''),
                $this->featureSpecs(),
            )),
            'files' => $generated,
        ];
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function featureSpecs(): array
    {
        return [
            [
                'feature' => 'create_checkout_session',
                'description' => 'Create billing checkout session.',
                'route' => ['method' => 'POST', 'path' => '/billing/checkout'],
                'input' => ['plan_key' => ['type' => 'string', 'required' => true]],
                'output' => ['checkout_url' => ['type' => 'string', 'required' => true]],
                'auth' => ['required' => true, 'strategies' => ['session'], 'permissions' => ['billing.manage']],
                'database' => ['reads' => ['subscriptions'], 'writes' => ['subscriptions'], 'queries' => ['create_checkout_session']],
                'tests' => ['required' => ['contract', 'feature', 'auth']],
            ],
            [
                'feature' => 'view_billing_portal',
                'description' => 'Get billing portal URL.',
                'route' => ['method' => 'GET', 'path' => '/billing/portal'],
                'input' => [],
                'output' => ['portal_url' => ['type' => 'string', 'required' => true]],
                'auth' => ['required' => true, 'strategies' => ['session'], 'permissions' => ['billing.manage']],
                'database' => ['reads' => ['subscriptions'], 'writes' => [], 'queries' => ['view_billing_portal']],
                'tests' => ['required' => ['contract', 'feature', 'auth']],
            ],
            [
                'feature' => 'handle_billing_webhook',
                'description' => 'Handle billing provider webhooks.',
                'route' => ['method' => 'POST', 'path' => '/billing/webhook'],
                'input' => ['event_id' => ['type' => 'string', 'required' => true]],
                'output' => ['status' => ['type' => 'string', 'required' => true]],
                'auth' => ['required' => false, 'strategies' => [], 'permissions' => []],
                'database' => ['reads' => ['billing_events'], 'writes' => ['billing_events', 'subscriptions'], 'queries' => ['upsert_billing_event']],
                'tests' => ['required' => ['contract', 'feature']],
            ],
            [
                'feature' => 'list_invoices',
                'description' => 'List user invoices.',
                'route' => ['method' => 'GET', 'path' => '/billing/invoices'],
                'input' => [],
                'output' => ['items' => ['type' => 'array', 'required' => true]],
                'auth' => ['required' => true, 'strategies' => ['session'], 'permissions' => ['billing.view']],
                'database' => ['reads' => ['invoices'], 'writes' => [], 'queries' => ['list_invoices']],
                'tests' => ['required' => ['contract', 'feature', 'auth']],
            ],
            [
                'feature' => 'view_current_subscription',
                'description' => 'View current subscription.',
                'route' => ['method' => 'GET', 'path' => '/billing/subscription'],
                'input' => [],
                'output' => ['status' => ['type' => 'string', 'required' => true]],
                'auth' => ['required' => true, 'strategies' => ['session'], 'permissions' => ['billing.view']],
                'database' => ['reads' => ['subscriptions'], 'writes' => [], 'queries' => ['view_current_subscription']],
                'tests' => ['required' => ['contract', 'feature', 'auth']],
            ],
        ];
    }
}
