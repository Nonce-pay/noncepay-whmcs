<?php

namespace NoncePay\Whmcs\Tests\Support;

use NoncePay\Whmcs\Api;

/**
 * A transport answering NoncePay routes from canned responses, recording every URL called.
 */
final class FakeApi
{
    /** @var string[] */
    public $calls = [];
    /** @var array<string,array{0:int,1:string}> path prefix => response */
    public $responses = [];
    /** @var int */
    private $created = 0;

    public function api(): Api
    {
        return new Api('https://api.test', function (string $url) {
            $this->calls[] = $url;
            $path = (string) parse_url($url, PHP_URL_PATH);
            foreach ($this->responses as $prefix => $response) {
                if (strpos($path, $prefix) === 0) {
                    return $response;
                }
            }
            if ($path === '/link/create/') {
                $this->created++;
                $id = sprintf('00000000-0000-4000-8000-%012d', $this->created);
                return [200, json_encode([
                    'status' => 'success',
                    'link_id' => $id,
                    'payment_url' => 'https://noncepay.app/pay/?id=' . $id,
                    'success_token' => 'token-' . $this->created,
                ])];
            }
            if (strpos($path, '/link/') === 0) {
                return [200, json_encode(['status' => 'success', 'link_status' => 'open'])];
            }
            return [404, json_encode(['status' => 'error', 'error' => 'not found'])];
        });
    }

    public function query(int $index): array
    {
        parse_str((string) parse_url($this->calls[$index], PHP_URL_QUERY), $query);
        return $query;
    }

    public function count(string $path): int
    {
        return count(array_filter($this->calls, function ($url) use ($path) {
            return parse_url($url, PHP_URL_PATH) === $path;
        }));
    }
}
