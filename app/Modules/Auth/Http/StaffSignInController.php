<?php

namespace App\Modules\Auth\Http;

use App\Exceptions\TooManyCodes;
use App\Http\Controllers\Controller;
use App\Mail\SignInCode;
use App\Models\AuditLog;
use App\Models\FacilityMembership;
use App\Models\User;
use App\Services\OneTimeCodes;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

/** Staff sign in on the web and desktop apps: a code sent to their email, or (demo accounts) a password. */
class StaffSignInController extends Controller
{
    public const TOKEN_HOURS = 12;

    public function start(Request $request, OneTimeCodes $codes)
    {
        $data = $request->validate(['email' => ['required', 'email:rfc', 'max:255']]);
        $email = Str::lower(trim($data['email']));

        $codes->throttle('staff_login', $email);

        $user = User::where('email', $email)->first();

        // Same answer whether or not the email belongs to staff.
        if (! $user) {
            return response()->json(['challenge' => $codes->decoy(), 'expires_in' => OneTimeCodes::TTL_MINUTES * 60]);
        }

        [$record, $plain] = $codes->issue('staff_login', 'email', $user);
        Mail::to($user->email)->send(new SignInCode($plain, $user->name));

        return response()->json(['challenge' => $record->id, 'expires_in' => OneTimeCodes::TTL_MINUTES * 60]);
    }

    public function verify(Request $request, OneTimeCodes $codes)
    {
        $data = $request->validate([
            'challenge' => ['required', 'string'],
            'code' => ['required', 'string', 'max:10'],
            'device_name' => ['required', 'string', 'max:100'],
        ]);

        $user = $codes->verify($data['challenge'], $data['code'], 'staff_login')->subject;

        if (! $user instanceof User) {
            abort(422, 'That code is wrong or has expired.');
        }

        $user->email_verified_at ??= now();
        $user->save();

        // Opening the invite email is the acceptance.
        FacilityMembership::where('user_id', $user->id)->where('status', 'invited')->update(['status' => 'active']);

        return $this->signIn($user, $data['device_name'], 'staff.sign_in', $request);
    }

    /**
     * Email and password. Only accounts given a password can use it (today:
     * the demo hospital's staff, see nhc:demo). Five wrong tries lock that
     * email for 15 minutes.
     */
    public function password(Request $request)
    {
        $data = $request->validate([
            'email' => ['required', 'email:rfc', 'max:255'],
            'password' => ['required', 'string', 'max:255'],
            'device_name' => ['required', 'string', 'max:100'],
        ]);
        $email = Str::lower(trim($data['email']));
        $key = 'staff-password:'.hash('sha256', $email);

        if (RateLimiter::tooManyAttempts($key, 5)) {
            throw new TooManyCodes(RateLimiter::availableIn($key));
        }

        $user = User::where('email', $email)->first();
        // Check a hash even for unknown emails, so timing doesn't reveal which exist.
        $hash = $user?->getAuthPassword() ?: self::dummyHash();
        if (! Hash::check($data['password'], $hash) || ! $user?->getAuthPassword()) {
            RateLimiter::hit($key, 15 * 60);
            abort(422, 'Wrong email or password.');
        }

        RateLimiter::clear($key);
        FacilityMembership::where('user_id', $user->id)->where('status', 'invited')->update(['status' => 'active']);

        return $this->signIn($user, $data['device_name'], 'staff.sign_in_password', $request);
    }

    private static function dummyHash(): string
    {
        static $hash;

        return $hash ??= Hash::make(Str::random(40));
    }

    private function signIn(User $user, string $device, string $action, Request $request)
    {
        AuditLog::create([
            'actor_type' => 'staff', 'actor_id' => $user->id, 'action' => $action,
            'ip_address' => $request->ip(), 'occurred_at' => now(),
        ]);

        return response()->json([
            'token' => $user->createToken($device, ['staff'], now()->addHours(self::TOKEN_HOURS))->plainTextToken,
            'expires_in' => self::TOKEN_HOURS * 3600,
        ]);
    }

    public function signOut(Request $request)
    {
        $request->user()->currentAccessToken()->delete();

        return response()->noContent();
    }
}
