<?php
declare(strict_types=1);

namespace Forge\Http;

use Forge\Support\Json;

final class ResponseEmitter
{
    /**
     * @param array{status:int,headers:array<string,string>,body:array<string,mixed>} $response
     */
    public function emit(array $response): string
    {
        if (!headers_sent()) {
            http_response_code($response['status']);
            foreach ($response['headers'] as $name => $value) {
                header($name . ': ' . $value);
            }
        }

        return Json::encode($response['body']);
    }
}
