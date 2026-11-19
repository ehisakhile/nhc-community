<?php

namespace App\Modules\Enquiries\Http;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Facility;
use App\Models\FacilityMembership;
use App\Models\User;
use App\Modules\Auth\Http\StaffSignInController;
use App\Modules\Enquiries\Mail\FacilityRegistered;
use App\Modules\Enquiries\Mail\RegistrationCode;
use App\Services\OneTimeCodes;
use App\Support\FacilityTypes;
use App\Support\HospifyPostcode;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Throwable;

/**
 * A facility registers itself from the NHC website:
 *   1. details + the admin's email -> we email a 6-digit code (nothing is created yet)
 *   2. the code -> the facility is created (pending), its admin is signed in, our team is told
 * A Hospify admin approves it (Hospify admin console); until then it can set up
 * (staff, features, catalogue) but can't register patients or take orders.
 */
class FacilityRegistrationController extends Controller
{
    private const PURPOSE = 'facility_register';

    public function start(Request $request, OneTimeCodes $codes)
    {
        $d = $request->validate([
            'facility_name' => ['required', 'string', 'min:2', 'max:200'],
            'type' => ['required', Rule::in(array_keys(FacilityTypes::all()))],
            'state' => ['required', 'string', 'min:2', 'max:100'],
            'lga' => ['nullable', 'string', 'max:100'],
            'address' => ['required', 'string', 'min:5', 'max:500'],
            'phone' => ['required', 'string', 'min:7', 'max:20'],
            'admin_name' => ['required', 'string', 'min:2', 'max:150'],
            'email' => ['required', 'email:rfc', 'max:255'],
            // "I'm at the facility": where it is, for its NIPOST postcode and the map.
            'latitude' => ['nullable', 'numeric', 'between:4,14'],
            'longitude' => ['nullable', 'numeric', 'between:2,15'],
        ]);
        $d['email'] = Str::lower(trim($d['email']));

        $codes->throttle(self::PURPOSE, $d['email']);
        [$record, $plain] = $codes->issue(self::PURPOSE, 'email', null, $d);
        Mail::to($d['email'])->send(new RegistrationCode($plain, $d['admin_name'], $d['facility_name']));

        return response()->json(['challenge' => $record->id, 'expires_in' => OneTimeCodes::TTL_MINUTES * 60]);
    }

    public function verify(Request $request, OneTimeCodes $codes)
    {
        $in = $request->validate([
            'challenge' => ['required', 'string'],
            'code' => ['required', 'string', 'max:10'],
            'device_name' => ['required', 'string', 'max:100'],
        ]);
        $d = $codes->verify($in['challenge'], $in['code'], self::PURPOSE)->payload;  // a wrong or old code answers 422

        [$facility, $admin] = DB::transaction(function () use ($d) {
            $facility = Facility::create([
                'name' => $d['facility_name'], 'type' => $d['type'], 'status' => 'pending',
                'email' => $d['email'], 'phone' => $d['phone'], 'state' => $d['state'], 'lga' => $d['lga'] ?? null,
                'address' => $d['address'], 'latitude' => $d['latitude'] ?? null, 'longitude' => $d['longitude'] ?? null,
            ]);
            $admin = User::firstOrCreate(['email' => $d['email']], ['name' => $d['admin_name']]);
            $admin->email_verified_at ??= now();
            $admin->save();
            FacilityMembership::updateOrCreate(['user_id' => $admin->id, 'facility_id' => $facility->id], ['role' => 'admin', 'status' => 'active']);

            return [$facility, $admin];
        });

        if ($code = HospifyPostcode::at($d['latitude'] ?? null, $d['longitude'] ?? null)) {
            $facility->update(['postcode' => $code['postcode']]);
        }

        AuditLog::create([
            'actor_type' => 'staff', 'actor_id' => $admin->id, 'facility_id' => $facility->id, 'action' => 'facility.registered',
            'ip_address' => $request->ip(), 'occurred_at' => now(),
        ]);
        if ($to = config('nhc.enquiries_to')) {
            try {
                Mail::to($to)->send(new FacilityRegistered($facility, $admin, $d));
            } catch (Throwable $e) {
                report($e);  // registered either way; it shows in the admin console
            }
        }

        return response()->json([
            'token' => $admin->createToken($in['device_name'], ['staff'], now()->addHours(StaffSignInController::TOKEN_HOURS))->plainTextToken,
            'expires_in' => StaffSignInController::TOKEN_HOURS * 3600,
            'facility_id' => $facility->id,
            'status' => 'pending',
            'postcode' => $facility->postcode,
        ], 201);
    }
}
