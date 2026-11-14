<?php

namespace NoncePay\Whmcs\Tests;

use NoncePay\Whmcs\Api;
use NoncePay\Whmcs\PublicKey;
use NoncePay\Whmcs\Tests\Support\MemoryStore;
use PHPUnit\Framework\TestCase;

final class PublicKeyTest extends TestCase
{
    /** @var string */
    private $served;
    /** @var int */
    private $fetches = 0;
    /** @var bool */
    private $down = false;
    /** @var int */
    private $now = 1700000000;

    public static function sign(string $data): string
    {
        $key = openssl_pkey_get_private((string) file_get_contents(__DIR__ . '/fixtures/test-private.pem'));
        openssl_sign($data, $signature, $key, OPENSSL_ALGO_SHA256);
        return base64_encode($signature);
    }

    public static function pem(string $name = 'test'): string
    {
        return (string) file_get_contents(__DIR__ . '/fixtures/' . $name . '-public.pem');
    }

    private function key(MemoryStore $store, string $pinned = ''): PublicKey
    {
        $api = new Api('https://api.test', function () {
            $this->fetches++;
            return $this->down ? [0, 'timeout'] : [200, json_encode(['status' => 'success', 'pubkey' => $this->served])];
        });
        return new PublicKey($api, $store, $pinned, function () {
            return $this->now;
        });
    }

    public function testVerifiesTheSignatureOfTheExactBytes(): void
    {
        $this->served = self::pem();
        $key = $this->key(new MemoryStore());
        $body = '{"type":"payment","link_id":"abc"}';
        $this->assertTrue($key->verify($body, self::sign($body)));
        $this->assertFalse($key->verify($body . ' ', self::sign($body)));
        $this->assertFalse($key->verify($body, ''));
        $this->assertFalse($key->verify($body, 'not base64!'));
        $this->assertSame(1, $this->fetches);
    }

    public function testRefetchesARotatedKeyAtMostOnceAnHour(): void
    {
        $store = new MemoryStore();
        $this->served = self::pem('other');
        $key = $this->key($store);
        $this->assertFalse($key->verify('x', self::sign('x')));
        $this->assertSame(1, $this->fetches);

        $this->served = self::pem();
        $this->assertFalse($key->verify('x', self::sign('x')), 'cache younger than an hour is kept');
        $this->now += 3601;
        $this->assertTrue($key->verify('x', self::sign('x')));
        $this->assertSame(2, $this->fetches);
    }

    public function testPausesFetchingAfterAnError(): void
    {
        $this->down = true;
        $key = $this->key(new MemoryStore());
        $this->assertFalse($key->verify('x', self::sign('x')));
        $this->assertFalse($key->verify('x', self::sign('x')));
        $this->assertSame(1, $this->fetches);
        $this->down = false;
        $this->served = self::pem();
        $this->now += 901;
        $this->assertTrue($key->verify('x', self::sign('x')));
    }

    public function testAPinnedKeyIsNeverReplaced(): void
    {
        $this->served = self::pem();
        $key = $this->key(new MemoryStore(), self::pem('other'));
        $this->assertFalse($key->verify('x', self::sign('x')));
        $this->assertSame(0, $this->fetches);
    }
}
