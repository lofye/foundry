<?php
declare(strict_types=1);

namespace Forge\Verification;

final class VerificationRunner
{
    /**
     * @param array<string,VerificationResult> $results
     * @return array{ok:bool,results:array<string,array{ok:bool,errors:array<int,string>,warnings:array<int,string>}>}
     */
    public function aggregate(array $results): array
    {
        $ok = true;
        $rows = [];

        foreach ($results as $name => $result) {
            $ok = $ok && $result->ok;
            $rows[$name] = $result->toArray();
        }

        ksort($rows);

        return [
            'ok' => $ok,
            'results' => $rows,
        ];
    }
}
