<?php

namespace App\Modules\Auth\Http;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\FacilityMembership;
use App\Models\User;
use App\Support\HospifyToken;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Exchanges a verified Hospify identity for staff access, never patient access. */
class HospifyStaffSignInController extends Controller
{
    public function exchange(Request $request): JsonResponse
    {
        $data = $request->validate([
            'assertion' => ['required', 'string', 'max:4000'],
            'device_name' => ['nullable', 'string', 'max:100'],
        ]);
        $claims = HospifyToken::verify($data['assertion'], 'staff:sign-in');
        $email = is_string($claims['email'] ?? null) ? Str::lower(trim($claims['email'])) : '';
        abort_unless(
            is_string($claims['sub'] ?? null) && Str::startsWith($claims['sub'], 'hfr_')
            && ($claims['email_verified'] ?? null) === true
            && strlen($email) <= 255 && filter_var($email, FILTER_VALIDATE_EMAIL)
            && ! Str::endsWith($email, ['@apple-anonymous.local', '@privaterelay.appleid.com'])
            && is_string($claims['name'] ?? null) && strlen($claims['name']) <= 255,
            401, 'Please sign in with a verified Hospify work email.'
        );

        return DB::transaction(function () use ($email, $claims, $data, $request): JsonResponse {
            $existing = User::withTrashed()->where('email', $email)->first();
            abort_if($existing?->trashed(), 403, 'This staff account is no longer available.');
            $user = $existing ?? User::firstOrCreate(['email' => $email], ['name' => $claims['name']]);
            $user->email_verified_at ??= now();
            $user->save();

            FacilityMembership::where('user_id', $user->id)->where('status', 'invited')->update(['status' => 'active']);
            $user->tokens()->where('expires_at', '<', now())->delete();
            AuditLog::create([
                'actor_type' => 'staff', 'actor_id' => $user->id, 'action' => 'staff.sign_in_hospify',
                'ip_address' => $request->ip(), 'occurred_at' => now(),
            ]);

            return response()->json([
                'token' => $user->createToken($data['device_name'] ?? 'Hospify Pro', ['staff'], now()->addHours(StaffSignInController::TOKEN_HOURS))->plainTextToken,
                'expires_in' => StaffSignInController::TOKEN_HOURS * 3600,
            ]);
        });
    }
}
