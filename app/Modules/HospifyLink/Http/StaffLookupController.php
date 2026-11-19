<?php

namespace App\Modules\HospifyLink\Http;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

/**
 * Sign in to Hospify Pro with an NHC staff account. Hospify's API asks this
 * only for an email that has no Hospify account yet:
 *
 * - without a password: is this email NHC staff? Hospify then emails its own
 *   sign-in code to that address, and creates the account once the code is
 *   entered (the code proves the inbox, not this answer);
 * - with a password: is it NHC staff, and is this their NHC password? (Only
 *   the demo hospital's staff have one.) Counted against the same limit as
 *   NHC's own password sign-in.
 *
 * Answers only for staff, never patients, and creates no token or session.
 */
class StaffLookupController extends Controller
{
    public function lookup(Request $request)
    {
        $data = $request->validate([
            'email' => ['required', 'email:rfc', 'max:255'],
            'password' => ['sometimes', 'nullable', 'string', 'max:255'],
        ]);
        $email = Str::lower(trim($data['email']));
        $user = User::where('email', $email)->first();   // soft-deleted staff stay out

        if (! array_key_exists('password', $data) || $data['password'] === null) {
            return response()->json($user
                ? ['staff' => true, 'name' => $user->name, 'email_verified' => $user->email_verified_at !== null]
                : ['staff' => false]);
        }

        $key = 'staff-password:'.hash('sha256', $email);
        if (RateLimiter::tooManyAttempts($key, 5)) {
            abort(429, 'Too many attempts. Try again in '.ceil(RateLimiter::availableIn($key) / 60).' minutes.');
        }
        $hash = $user?->getAuthPassword();
        if (! $hash || ! Hash::check($data['password'], $hash)) {
            RateLimiter::hit($key, 15 * 60);

            return response()->json(['staff' => false]);
        }
        RateLimiter::clear($key);

        return response()->json(['staff' => true, 'name' => $user->name, 'email_verified' => $user->email_verified_at !== null]);
    }
}
