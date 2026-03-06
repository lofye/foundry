<?php
declare(strict_types=1);

namespace Forge\Tests\Unit;

use Forge\Observability\AuditRecorder;
use Forge\Observability\MetricsRecorder;
use Forge\Observability\StructuredLogger;
use Forge\Observability\TraceContext;
use Forge\Observability\TraceRecorder;
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
        $this->assertSame(1.0, $metrics->counters()['requests']);

        $trace = new TraceRecorder(new TraceContext('trace-id'));
        $trace->record('feature', 'http', 'start');
        $this->assertCount(1, $trace->events());
        $this->assertSame('trace-id', $trace->events()[0]['trace_id']);

        $audit = new AuditRecorder();
        $audit->record('feature', 'action');
        $this->assertCount(1, $audit->events());
    }
}
