<?php

namespace App\Services;

use App\Exceptions\CodeRejected;
use App\Exceptions\TooManyCodes;
use App\Models\OneTimeCode;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

/**
 * Six-digit codes, ten minutes, five tries, one use. Limits are keyed on what
 * the person typed, so an unknown email or ID is throttled exactly like a real one.
 */
class OneTimeCodes
{
    public const TTL_MINUTES = 10;

    private const MAX_ATTEMPTS = 5;

    private const PER_HOUR = 5;

    /** Throws TooManyCodes if this identifier asked too often. */
    public function throttle(string $purpose, string $identifier): void
    {
        $key = 'otp:'.$purpose.':'.hash('sha256', Str::lower($identifier));

        if (RateLimiter::tooManyAttempts($key, self::PER_HOUR)) {
            throw new TooManyCodes(RateLimiter::availableIn($key));
        }

        RateLimiter::hit($key, 3600);
    }

    /** @return array{0: OneTimeCode, 1: string} the record and the plain code to send */
    public function issue(string $purpose, string $channel, ?Model $subject = null, array $payload = []): array
    {
        $plain = str_pad((string) random_int(0, 999_999), 6, '0', STR_PAD_LEFT);

        $record = new OneTimeCode;
        $record->id = (string) Str::ulid();
        $record->purpose = $purpose;
        $record->channel = $channel;
        $record->subject()->associate($subject);
        $record->payload = $payload ?: null;
        $record->code_hash = $this->hash($record->id, $plain);
        $record->expires_at = now()->addMinutes(self::TTL_MINUTES);
        $record->save();

        return [$record, $plain];
    }

    /** A challenge ID for someone we won't send a code to. It can never be verified. */
    public function decoy(): string
    {
        return (string) Str::ulid();
    }

    public function verify(string $challenge, string $code, string $purpose): OneTimeCode
    {
        $record = Str::isUlid($challenge) ? OneTimeCode::find($challenge) : null;

        if (! $record || $record->purpose !== $purpose || $record->consumed_at || $record->expires_at->isPast()) {
            throw new CodeRejected;
        }

        // Count the try first, atomically, so parallel guesses can't share one attempt.
        $counted = OneTimeCode::whereKey($record->id)
            ->where('attempts', '<', self::MAX_ATTEMPTS)
            ->increment('attempts');

        if (! $counted || ! hash_equals($record->code_hash, $this->hash($record->id, trim($code)))) {
            throw new CodeRejected;
        }

        // One use only, even if two requests arrive together.
        $claimed = OneTimeCode::whereKey($record->id)->whereNull('consumed_at')->update(['consumed_at' => now()]);

        if (! $claimed) {
            throw new CodeRejected;
        }

        return $record->refresh();
    }

    private function hash(string $id, string $code): string
    {
        return hash_hmac('sha256', $id.'|'.$code, config('app.key'));
    }
}
