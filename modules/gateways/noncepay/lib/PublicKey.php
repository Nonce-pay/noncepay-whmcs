<?php

namespace NoncePay\Whmcs;

/**
 * Verifies x-ca-signature (base64 RSA-SHA256, PKCS#1 v1.5) against the NoncePay public key.
 *
 * The key is pinned in the settings, or fetched from /pubkey/ and cached; on a failed check an
 * unpinned key is fetched again (at most once an hour, with a 15-minute pause after an error).
 */
final class PublicKey
{
    const CACHE = 'pubkey';
    const RETRY = 'pubkey_retry_after';

    /** @var Api */
    private $api;
    /** @var Store */
    private $store;
    /** @var string */
    private $pinned;
    /** @var callable(): int */
    private $clock;

    public function __construct(Api $api, Store $store, string $pinned, ?callable $clock = null)
    {
        $this->api = $api;
        $this->store = $store;
        $this->pinned = $pinned;
        $this->clock = $clock ?: 'time';
    }

    public function verify(string $data, string $signature): bool
    {
        $raw = base64_decode($signature, true);
        if ($signature === '' || $raw === false || $raw === '') {
            return false;
        }
        $key = $this->key(false);
        if ($key !== '' && self::check($data, $raw, $key)) {
            return true;
        }
        if ($this->pinned !== '') {
            return false;
        }
        $fresh = $this->key(true);
        return $fresh !== '' && $fresh !== $key && self::check($data, $raw, $fresh);
    }

    private static function check(string $data, string $raw, string $pem): bool
    {
        $key = openssl_pkey_get_public($pem);
        return $key !== false && openssl_verify($data, $raw, $key, OPENSSL_ALGO_SHA256) === 1;
    }

    private function key(bool $refresh): string
    {
        if ($this->pinned !== '') {
            return $this->pinned;
        }
        $now = (int) call_user_func($this->clock);
        $cached = json_decode((string) $this->store->getValue(self::CACHE), true);
        $pem = is_array($cached) && !empty($cached['pem']) ? (string) $cached['pem'] : '';
        if ($pem !== '' && (!$refresh || $now - (int) $cached['fetched_at'] < 3600)) {
            return $pem;
        }
        if ((int) $this->store->getValue(self::RETRY) > $now) {
            return $pem;
        }
        try {
            $fresh = $this->api->publicKey();
        } catch (ApiException $e) {
            $this->store->setValue(self::RETRY, (string) ($now + 900));
            return $pem;
        }
        $this->store->setValue(self::CACHE, json_encode(['pem' => $fresh, 'fetched_at' => $now]));
        return $fresh;
    }
}
