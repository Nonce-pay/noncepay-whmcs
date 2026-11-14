<?php

namespace NoncePay\Whmcs;

/**
 * Client for the NoncePay HTTP API. Calls only the configured base URL.
 */
final class Api
{
    const VERSION = '0.1.1';

    /** @var string */
    private $base;
    /** @var callable(string): array{0:int,1:string} */
    private $transport;
    /** @var callable|null fn(string $action, string $url, $response) for the WHMCS module log */
    private $logger;

    public function __construct(string $base, ?callable $transport = null, ?callable $logger = null)
    {
        $this->base = rtrim($base, '/');
        $this->transport = $transport ?: [self::class, 'curlGet'];
        $this->logger = $logger;
    }

    /**
     * @return array{link_id:string,payment_url:string,success_token:string}
     * @throws ApiException
     */
    public function createLink(array $query): array
    {
        $body = $this->get('/link/create/', $query);
        foreach (['link_id', 'payment_url', 'success_token'] as $field) {
            if (!isset($body[$field]) || !is_string($body[$field]) || $body[$field] === '') {
                throw new ApiException('NoncePay answered /link/create/ without ' . $field);
            }
        }
        if (!preg_match('/^[A-Za-z0-9-]{1,64}$/', $body['link_id']) || strpos($body['payment_url'], 'https://') !== 0) {
            throw new ApiException('NoncePay answered /link/create/ with an unexpected link');
        }
        return $body;
    }

    /** @throws ApiException */
    public function linkStatus(string $linkId): string
    {
        $body = $this->get('/link/' . rawurlencode($linkId) . '/');
        return isset($body['link_status']) && is_string($body['link_status']) ? $body['link_status'] : '';
    }

    /** @throws ApiException */
    public function publicKey(): string
    {
        $body = $this->get('/pubkey/');
        if (empty($body['pubkey']) || !is_string($body['pubkey'])) {
            throw new ApiException('NoncePay answered /pubkey/ without a key');
        }
        return $body['pubkey'];
    }

    /** @throws ApiException */
    private function get(string $path, array $query = []): array
    {
        $url = $this->base . $path . ($query ? '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986) : '');
        list($code, $raw) = call_user_func($this->transport, $url);
        $body = json_decode((string) $raw, true);
        if ($this->logger) {
            call_user_func($this->logger, $path, $url, is_array($body) ? $body : (string) $raw);
        }
        if ($code === 0) {
            throw new ApiException('NoncePay API unreachable: ' . $raw);
        }
        if (!is_array($body)) {
            throw new ApiException(sprintf('NoncePay API %s answered HTTP %d with a non-JSON body', $path, $code));
        }
        if ($code !== 200 || (isset($body['status']) && $body['status'] === 'error')) {
            $message = isset($body['error']) && is_string($body['error']) ? $body['error'] : 'HTTP ' . $code;
            throw new ApiException(sprintf('NoncePay API %s error (HTTP %d): %s', $path, $code, $message));
        }
        return $body;
    }

    /**
     * @return array{0:int,1:string} HTTP status (0 when unreachable) and body (or the error).
     */
    public static function curlGet(string $url): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS | CURLPROTO_HTTP,
            CURLOPT_USERAGENT => 'NoncePay-for-WHMCS/' . self::VERSION,
            CURLOPT_HTTPHEADER => ['Accept: application/json'],
        ]);
        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        if ($body === false) {
            return [0, $error];
        }
        return [$code, (string) $body];
    }
}
