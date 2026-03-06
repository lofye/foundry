<?php
declare(strict_types=1);

namespace Forge\Core;

use Forge\Auth\AuthorizationEngine;
use Forge\Config\ConfigRepository;
use Forge\Feature\FeatureExecutor;
use Forge\Feature\FeatureLoader;
use Forge\Http\HttpKernel;
use Forge\Observability\AuditRecorder;
use Forge\Observability\MetricsRecorder;
use Forge\Observability\StructuredLogger;
use Forge\Observability\TraceContext;
use Forge\Observability\TraceRecorder;
use Forge\Schema\SchemaRegistry;
use Forge\Schema\SchemaValidator;
use Forge\Support\Paths;

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
    ) {
    }

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
