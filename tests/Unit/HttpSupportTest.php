<?php
declare(strict_types=1);

namespace Foundry\Tests\Unit;

use Foundry\Http\RequestContext;
use Foundry\Http\ResponseEmitter;
use PHPUnit\Framework\TestCase;

final class HttpSupportTest extends TestCase
{
    public function test_request_context_input_merges_query_body_and_params(): void
    {
        $request = new RequestContext('POST', '/x', [], ['a' => 1], ['b' => 2], ['id' => '3']);
        $this->assertSame(['a' => 1, 'b' => 2, 'id' => '3'], $request->input());
    }

    public function test_response_emitter_returns_json_string(): void
    {
        $json = (new ResponseEmitter())->emit([
            'status' => 200,
            'headers' => ['content-type' => 'application/json'],
            'body' => ['ok' => true],
        ]);

        $this->assertSame('{"ok":true}', $json);
    }
}
