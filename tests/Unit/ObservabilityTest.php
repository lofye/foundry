<?php

declare(strict_types=1);

namespace Foundry\Tests\Unit;

use Foundry\Observability\AuditRecorder;
use Foundry\Observability\MetricsRecorder;
use Foundry\Observability\StructuredLogger;
use Foundry\Observability\TraceContext;
use Foundry\Observability\TraceRecorder;
use PHPUnit\Framework\TestCase;

final class ObservabilityTest extends TestCase
{
    public function test_logger_metrics_trace_and_audit_record_data(): void
    {
        $logger = new StructuredLogger();
        $logger->log('info', 'hello', ['a' => 1]);
        $this->assertCount(1, $logger->records());

        $metrics = new MetricsRecorder();
        $metrics->increment('requests');
        $metrics->observe('latency_ms', 12.3);
        $metrics->observe('a_latency_ms', 10.1);
        $this->assertSame(1.0, $metrics->counters()['requests']);
        $this->assertSame(['a_latency_ms' => 10.1, 'latency_ms' => 12.3], $metrics->timings());

        $trace = new TraceRecorder(new TraceContext('trace-id'));
        $trace->record('feature', 'http', 'start');
        $this->assertCount(1, $trace->events());
        $this->assertSame('trace-id', $trace->events()[0]['trace_id']);

        $audit = new AuditRecorder();
        $audit->record('feature', 'action');
        $this->assertCount(1, $audit->events());
    }
}
