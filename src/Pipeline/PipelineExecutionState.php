<?php
declare(strict_types=1);

namespace Foundry\Pipeline;

use Foundry\Auth\AuthContext;
use Foundry\Feature\FeatureDefinition;
use Foundry\Feature\FeatureServices;
use Foundry\Http\RequestContext;
use Foundry\Http\Route;
use Foundry\Observability\AuditRecorder;
use Foundry\Observability\TraceRecorder;

final class PipelineExecutionState
{
    public ?AuthContext $auth = null;

    /**
     * @var array<string,mixed>
     */
    public array $input = [];

    /**
     * @var array<string,mixed>
     */
    public array $output = [];

    public bool $transactionOpened = false;

    /**
     * @var array<string,mixed>
     */
    public array $metadata = [];

    public function __construct(
        public RequestContext $request,
        public readonly Route $route,
        public readonly FeatureDefinition $feature,
        public readonly FeatureServices $services,
        public readonly TraceRecorder $trace,
        public readonly AuditRecorder $audit,
    ) {
        $this->input = $request->input();
    }
}

