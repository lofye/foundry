<?php
declare(strict_types=1);

namespace Foundry\Pro\Generation;

use Foundry\AI\AIManager;
use Foundry\AI\AIProviderRegistry;
use Foundry\AI\AIRequest;
use Foundry\Compiler\CompileOptions;
use Foundry\Compiler\GraphCompiler;
use Foundry\Compiler\GraphVerifier;
use Foundry\Compiler\Prompt\GraphPromptBuilder;
use Foundry\Generation\FeatureGenerator;
use Foundry\Generation\WorkflowGenerator;
use Foundry\Support\CliCommandPrefix;
use Foundry\Support\FoundryError;
use Foundry\Support\Paths;
use Foundry\Verification\ContractsVerifier;
use Foundry\Verification\WorkflowVerifier;

final class AIGenerationService
{
    public function __construct(
        private readonly Paths $paths,
        private readonly GraphCompiler $compiler,
        private readonly GraphVerifier $graphVerifier,
        private readonly FeatureGenerator $featureGenerator,
        private readonly WorkflowGenerator $workflowGenerator,
        private readonly ContractsVerifier $contractsVerifier,
        private readonly WorkflowVerifier $workflowVerifier,
        private readonly AIProviderRegistry $providerRegistry = new AIProviderRegistry(),
    ) {
    }

    /**
     * @param array<string,mixed> $options
     * @return array<string,mixed>
     */
    public function generate(string $prompt, array $options = []): array
    {
        $featureContext = (bool) ($options['feature_context'] ?? false);
        $dryRun = (bool) ($options['dry_run'] ?? false);
        $deterministic = (bool) ($options['deterministic'] ?? false);
        $force = (bool) ($options['force'] ?? false);

        $preflight = $this->compiler->compile(new CompileOptions());
        $builder = new GraphPromptBuilder($this->compiler->impactAnalyzer(), CliCommandPrefix::foundry($this->paths));
        $bundle = $builder->build($preflight->graph, $prompt, $featureContext);

        $planner = new PromptFeaturePlanner();
        $providerPayload = [
            'mode' => $deterministic ? 'deterministic' : 'provider',
            'provider' => null,
            'model' => null,
            'cache_hit' => false,
            'input_tokens' => 0,
            'output_tokens' => 0,
            'cost_estimate' => 0.0,
        ];
        $providerPlan = [];

        if (!$deterministic) {
            [$config, $providerName, $model] = $this->providerSelection($options);
            $providers = $this->providerRegistry->providersFromConfig($config);
            if (!isset($providers[$providerName])) {
                throw new FoundryError(
                    'PRO_GENERATE_PROVIDER_NOT_CONFIGURED',
                    'validation',
                    ['provider' => $providerName],
                    'No AI provider is configured for generation. Configure config/ai.php or use --deterministic.',
                );
            }

            $manager = new AIManager($providers);
            $response = $manager->complete(new AIRequest(
                provider: $providerName,
                model: $model,
                prompt: $this->providerPrompt($bundle),
                input: [
                    'instruction' => $prompt,
                    'selected_features' => $bundle['selected_features'] ?? [],
                    'context_nodes' => $bundle['context_bundle']['node_counts'] ?? [],
                ],
                responseSchema: $planner->responseSchema(),
                temperature: 0.0,
            ));

            $providerPlan = $response->parsed;
            $providerPayload = [
                'mode' => 'provider',
                'provider' => $response->provider,
                'model' => $response->model,
                'cache_hit' => $response->cacheHit,
                'input_tokens' => $response->inputTokens,
                'output_tokens' => $response->outputTokens,
                'cost_estimate' => $response->costEstimate,
                'metadata' => $response->metadata,
            ];
        }

        $plan = $planner->plan($prompt, $bundle, $providerPlan, $deterministic);
        $applier = new GeneratedFeatureApplier($this->paths, $this->featureGenerator, $this->workflowGenerator);
        $predictedFiles = $applier->predictedFiles($plan);

        if ($dryRun) {
            return [
                'status' => 0,
                'payload' => [
                    'ok' => true,
                    'dry_run' => true,
                    'deterministic' => $deterministic,
                    'prompt' => $prompt,
                    'provider' => $providerPayload,
                    'plan' => $plan,
                    'predicted_files' => $predictedFiles,
                    'context' => $bundle,
                    'preflight' => $this->preflightPayload($preflight),
                ],
            ];
        }

        $generated = $applier->apply($plan, $force);
        $compiled = $this->compiler->compile(new CompileOptions());
        $graphVerification = $this->graphVerifier->verify();
        $contractsVerification = $this->contractsVerifier->verify();
        $workflowVerification = is_array($plan['workflow'] ?? null)
            ? $this->workflowVerifier->verify()
            : null;

        $compileSummary = $compiled->diagnostics->summary();
        $status = (int) ($compileSummary['error'] ?? 0) > 0 || !$graphVerification->ok || !$contractsVerification->ok
            ? 1
            : 0;
        if ($workflowVerification !== null && !$workflowVerification->ok) {
            $status = 1;
        }

        return [
            'status' => $status,
            'payload' => [
                'ok' => $status === 0,
                'dry_run' => false,
                'deterministic' => $deterministic,
                'prompt' => $prompt,
                'provider' => $providerPayload,
                'plan' => $plan,
                'predicted_files' => $predictedFiles,
                'generated' => $generated,
                'context' => $bundle,
                'preflight' => $this->preflightPayload($preflight),
                'compile' => $compiled->toArray(),
                'verification' => [
                    'graph' => $graphVerification->toArray(),
                    'contracts' => $contractsVerification->toArray(),
                    'workflow' => $workflowVerification?->toArray(),
                ],
            ],
        ];
    }

    /**
     * @param array<string,mixed> $options
     * @return array{0:array<string,mixed>,1:string,2:string}
     */
    private function providerSelection(array $options): array
    {
        $config = (new AIConfigLoader())->load($this->paths);
        $provider = trim((string) ($options['provider'] ?? ($config['default'] ?? '')));
        if ($provider === '') {
            throw new FoundryError(
                'PRO_GENERATE_PROVIDER_NOT_CONFIGURED',
                'validation',
                [],
                'No AI provider is configured for generation. Configure config/ai.php or use --deterministic.',
            );
        }

        $providerConfig = is_array(($config['providers'] ?? [])[$provider] ?? null)
            ? (array) (($config['providers'] ?? [])[$provider] ?? [])
            : [];
        $model = trim((string) ($options['model'] ?? ($providerConfig['model'] ?? 'foundry-pro-generator')));

        return [$config, $provider, $model];
    }

    /**
     * @param array<string,mixed> $bundle
     */
    private function providerPrompt(array $bundle): string
    {
        $promptText = trim((string) ($bundle['prompt']['text'] ?? ''));

        return $promptText . "\n\nReturn JSON with keys: feature, workflow, explanation.";
    }

    /**
     * @return array<string,mixed>
     */
    private function preflightPayload(mixed $compileResult): array
    {
        if (!$compileResult instanceof \Foundry\Compiler\CompileResult) {
            return [];
        }

        return [
            'graph' => [
                'graph_version' => $compileResult->graph->graphVersion(),
                'framework_version' => $compileResult->graph->frameworkVersion(),
                'compiled_at' => $compileResult->graph->compiledAt(),
                'source_hash' => $compileResult->graph->sourceHash(),
            ],
            'diagnostics' => [
                'summary' => $compileResult->diagnostics->summary(),
                'items' => $compileResult->diagnostics->toArray(),
            ],
        ];
    }
}
