<?php

declare(strict_types=1);

namespace Foundry\Tests\Unit;

use Foundry\Observability\ObservationComparator;
use PHPUnit\Framework\TestCase;

final class ObservationComparatorTest extends TestCase
{
    public function test_compares_quality_profile_and_trace_records(): void
    {
        $comparator = new ObservationComparator();

        $quality = $comparator->compare(
            [
                'id' => 'quality-a',
                'kind' => 'quality',
                'payload' => [
                    'diagnostics_summary' => ['error' => 0],
                    'static_analysis' => ['summary' => ['total' => 0]],
                    'style_violations' => ['summary' => ['total' => 0]],
                ],
            ],
            [
                'id' => 'quality-b',
                'kind' => 'quality',
                'payload' => [
                    'diagnostics_summary' => ['error' => 1],
                    'static_analysis' => ['summary' => ['total' => 2]],
                    'style_violations' => ['summary' => ['total' => 1]],
                ],
            ],
        );
        $this->assertCount(3, $quality['regressions']);

        $profile = $comparator->compare(
            [
                'id' => 'profile-a',
                'kind' => 'profile',
                'payload' => [
                    'timings' => ['compile_ms' => 10.0],
                    'memory' => ['peak_bytes' => 1000],
                    'execution_profiles' => [[
                        'feature' => 'publish_post',
                        'execution_plan' => 'execution_plan:feature:publish_post',
                        'route_signature' => 'POST /posts',
                        'pipeline_stages' => ['routing', 'action'],
                        'guards' => ['guard:auth:publish_post'],
                        'interceptors' => [],
                    ]],
                ],
            ],
            [
                'id' => 'profile-b',
                'kind' => 'profile',
                'payload' => [
                    'timings' => ['compile_ms' => 20.0],
                    'memory' => ['peak_bytes' => 2500],
                    'execution_profiles' => [[
                        'feature' => 'publish_post',
                        'execution_plan' => 'execution_plan:feature:publish_post',
                        'route_signature' => 'POST /posts',
                        'pipeline_stages' => ['routing', 'validation', 'action'],
                        'guards' => ['guard:auth:publish_post', 'guard:request_validation:publish_post'],
                        'interceptors' => [],
                    ]],
                ],
            ],
        );
        $this->assertCount(2, $profile['regressions']);
        $this->assertCount(1, $profile['changed_execution_paths']);

        $trace = $comparator->compare(
            [
                'id' => 'trace-a',
                'kind' => 'trace',
                'payload' => [
                    'execution_paths' => [[
                        'feature' => 'publish_post',
                        'execution_plan' => 'execution_plan:feature:publish_post',
                        'route_signature' => 'POST /posts',
                        'pipeline_stages' => ['routing', 'action'],
                        'guards' => [['id' => 'guard:auth:publish_post']],
                        'interceptors' => [],
                    ]],
                ],
            ],
            [
                'id' => 'trace-b',
                'kind' => 'trace',
                'payload' => [
                    'execution_paths' => [[
                        'feature' => 'publish_post',
                        'execution_plan' => 'execution_plan:feature:publish_post',
                        'route_signature' => 'POST /posts',
                        'pipeline_stages' => ['routing', 'validation', 'action'],
                        'guards' => [['id' => 'guard:auth:publish_post']],
                        'interceptors' => [['id' => 'interceptor:trace.request_received']],
                    ]],
                ],
            ],
        );
        $this->assertCount(1, $trace['changed_execution_paths']);
    }
}
