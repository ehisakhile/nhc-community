<?php

namespace App\Support;

use UnexpectedValueException;

/**
 * ES256 JSON Web Tokens with PHP's OpenSSL. Only ES256 is accepted: a token
 * that names any other algorithm is refused before its signature is read.
 */
final class Es256Jwt
{
    private const LEEWAY = 30;

    public static function sign(array $claims, string $privateKey, string $kid): string
    {
        $header = self::b64(json_encode(['alg' => 'ES256', 'typ' => 'JWT', 'kid' => $kid]));
        $body = self::b64(json_encode($claims));

        if (! openssl_sign("$header.$body", $der, self::pem($privateKey), OPENSSL_ALGO_SHA256)) {
            throw new UnexpectedValueException('Could not sign the token.');
        }

        return "$header.$body.".self::b64(self::derToRaw($der));
    }

    /** @return array<string, mixed> the claims, once signature and time are good */
    public static function verify(string $token, string $publicKey): array
    {
        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            throw new UnexpectedValueException('Malformed token.');
        }
        [$header, $body, $signature] = $parts;

        $head = json_decode(self::unb64($header), true);
        if (($head['alg'] ?? null) !== 'ES256') {
            throw new UnexpectedValueException('Only ES256 tokens are accepted.');
        }

        $raw = self::unb64($signature);
        if (strlen($raw) !== 64) {
            throw new UnexpectedValueException('Bad signature.');
        }

        if (openssl_verify("$header.$body", self::rawToDer($raw), self::pem($publicKey), OPENSSL_ALGO_SHA256) !== 1) {
            throw new UnexpectedValueException('Bad signature.');
        }

        $claims = json_decode(self::unb64($body), true);
        if (! is_array($claims) || ! is_int($claims['exp'] ?? null) || $claims['exp'] < time() - self::LEEWAY) {
            throw new UnexpectedValueException('Token expired.');
        }
        if (isset($claims['nbf']) && $claims['nbf'] > time() + self::LEEWAY) {
            throw new UnexpectedValueException('Token not valid yet.');
        }

        return $claims;
    }

    /** Keys may be given as PEM or as base64 of the PEM (easier in .env). */
    private static function pem(string $key): string
    {
        return str_contains($key, 'BEGIN') ? str_replace('\n', "\n", $key) : (string) base64_decode($key, true);
    }

    private static function b64(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    private static function unb64(string $data): string
    {
        return (string) base64_decode(strtr($data, '-_', '+/'), true);
    }

    /** OpenSSL gives DER; JWT carries the raw 32-byte r and s. */
    private static function derToRaw(string $der): string
    {
        $pos = 2;
        $out = '';
        for ($i = 0; $i < 2; $i++) {
            $len = ord($der[$pos + 1]);
            $int = ltrim(substr($der, $pos + 2, $len), "\0");
            $out .= str_pad($int, 32, "\0", STR_PAD_LEFT);
            $pos += 2 + $len;
        }

        return $out;
    }

    private static function rawToDer(string $raw): string
    {
        $seq = '';
        foreach (str_split($raw, 32) as $int) {
            $int = ltrim($int, "\0");
            if ($int === '' || ord($int[0]) > 0x7F) {
                $int = "\0".$int;
            }
            $seq .= "\x02".chr(strlen($int)).$int;
        }

        return "\x30".chr(strlen($seq)).$seq;
    }
}
