<?php

namespace Tests\Unit;

use App\Support\Es256Jwt;
use PHPUnit\Framework\TestCase;
use UnexpectedValueException;

class Es256JwtTest extends TestCase
{
    /** @return array{0: string, 1: string} private and public PEM */
    private function keys(): array
    {
        $key = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
        openssl_pkey_export($key, $private);

        return [$private, openssl_pkey_get_details($key)['key']];
    }

    public function test_a_signed_token_verifies_and_returns_its_claims(): void
    {
        [$private, $public] = $this->keys();

        // Many signatures, so r or s with a leading zero byte gets exercised.
        for ($i = 0; $i < 50; $i++) {
            $token = Es256Jwt::sign(['n' => $i, 'exp' => time() + 60], $private, 'k');
            $this->assertSame($i, Es256Jwt::verify($token, $public)['n']);
        }
    }

    public function test_keys_can_be_given_as_base64(): void
    {
        [$private, $public] = $this->keys();

        $token = Es256Jwt::sign(['exp' => time() + 60], base64_encode($private), 'k');
        $this->assertIsArray(Es256Jwt::verify($token, base64_encode($public)));
    }

    public function test_a_changed_claim_breaks_the_signature(): void
    {
        [$private, $public] = $this->keys();
        [$head, , $sig] = explode('.', Es256Jwt::sign(['role' => 'patient', 'exp' => time() + 60], $private, 'k'));
        $forged = rtrim(strtr(base64_encode(json_encode(['role' => 'admin', 'exp' => time() + 60])), '+/', '-_'), '=');

        $this->expectException(UnexpectedValueException::class);
        Es256Jwt::verify("$head.$forged.$sig", $public);
    }

    public function test_expired_tokens_are_refused(): void
    {
        [$private, $public] = $this->keys();

        $this->expectException(UnexpectedValueException::class);
        Es256Jwt::verify(Es256Jwt::sign(['exp' => time() - 120], $private, 'k'), $public);
    }

    public function test_tokens_without_an_expiry_are_refused(): void
    {
        [$private, $public] = $this->keys();

        $this->expectException(UnexpectedValueException::class);
        Es256Jwt::verify(Es256Jwt::sign(['sub' => 'x'], $private, 'k'), $public);
    }
}
