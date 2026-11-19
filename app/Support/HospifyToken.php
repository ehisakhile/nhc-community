<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpKernel\Exception\HttpException;
use UnexpectedValueException;

/**
 * Checks a token Hospify signed: ES256 with Hospify's key, issued by Hospify for
 * NHC, five minutes at most, used once. Anything else is a 401.
 */
final class HospifyToken
{
    /** @return array<string, mixed> */
    public static function verify(?string $token, ?string $scope = null): array
    {
        $key = config('services.hospify.public_key');
        if (blank($key)) {
            throw new HttpException(503, 'Hospify is not set up on NHC.');
        }

        try {
            $claims = Es256Jwt::verify((string) $token, $key);
        } catch (UnexpectedValueException $e) {
            Log::notice('Hospify token refused', ['reason' => $e->getMessage()]);
            throw self::refuse();
        }

        $valid = ($claims['iss'] ?? null) === 'hospify'
            && ($claims['aud'] ?? null) === 'nhc'
            && is_string($claims['jti'] ?? null)
            && is_int($claims['iat'] ?? null)
            && $claims['exp'] - $claims['iat'] <= 300
            && ($scope === null || ($claims['scope'] ?? null) === $scope);

        if (! $valid || ! Cache::add('hospify-jti:'.$claims['jti'], true, 600)) {
            throw self::refuse();
        }

        return $claims;
    }

    private static function refuse(): HttpException
    {
        return new HttpException(401, 'Please sign in to Hospify again.');
    }
}
