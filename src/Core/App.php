<?php

declare(strict_types=1);

namespace Foundry\Core;

use Foundry\Auth\AuthorizationEngine;
use Foundry\Config\ConfigRepository;
use Foundry\Feature\FeatureExecutor;
use Foundry\Feature\FeatureLoader;
use Foundry\Http\HttpKernel;
use Foundry\Observability\AuditRecorder;
use Foundry\Observability\MetricsRecorder;
use Foundry\Observability\StructuredLogger;
use Foundry\Observability\TraceContext;
use Foundry\Observability\TraceRecorder;
use Foundry\Schema\SchemaRegistry;
use Foundry\Schema\SchemaValidator;
use Foundry\Support\Paths;

final class App
{
    public function __construct(
        private readonly Paths $paths,
        private readonly Environment $environment,
        private readonly ConfigRepository $config,
        private readonly FeatureLoader $featureLoader,
        private readonly SchemaRegistry $schemaRegistry,
        private readonly SchemaValidator $schemaValidator,
        private readonly AuthorizationEngine $authorization,
        private readonly StructuredLogger $logger,
        private readonly TraceContext $traceContext,
        private readonly TraceRecorder $traceRecorder,
        private readonly MetricsRecorder $metrics,
        private readonly AuditRecorder $audit,
    ) {}

    public function httpKernel(FeatureExecutor $executor): HttpKernel
    {
        return new HttpKernel($executor, $this->logger);
    }

    public function paths(): Paths
    {
        return $this->paths;
    }

    public function env(): Environment
    {
        return $this->environment;
    }

    public function config(): ConfigRepository
    {
        return $this->config;
    }

    public function featureLoader(): FeatureLoader
    {
        return $this->featureLoader;
    }

    public function schemaRegistry(): SchemaRegistry
    {
        return $this->schemaRegistry;
    }

    public function schemaValidator(): SchemaValidator
    {
        return $this->schemaValidator;
    }

    public function authz(): AuthorizationEngine
    {
        return $this->authorization;
    }

    public function logger(): StructuredLogger
    {
        return $this->logger;
    }

    public function traceContext(): TraceContext
    {
        return $this->traceContext;
    }

    public function traceRecorder(): TraceRecorder
    {
        return $this->traceRecorder;
    }

    public function metrics(): MetricsRecorder
    {
        return $this->metrics;
    }

    public function audit(): AuditRecorder
    {
        return $this->audit;
    }
}
