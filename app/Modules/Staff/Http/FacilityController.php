<?php

namespace App\Modules\Staff\Http;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Facility;
use App\Models\FacilityMembership;
use App\Models\User;
use App\Modules\Enquiries\Mail\FacilityRegistered;
use App\Modules\Staff\ProvisionRegisteredFacility;
use App\Support\FacilityTypes;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Throwable;

class FacilityController extends Controller
{
    public function store(Request $request, ProvisionRegisteredFacility $provisioning): JsonResponse
    {
        $data = $request->validate([
            'request_id' => ['required', 'uuid'],
            'name' => ['required', 'string', 'min:2', 'max:200'],
            'type' => ['required', Rule::in(array_keys(FacilityTypes::all()))],
            'state' => ['required', 'string', 'min:2', 'max:100'],
            'lga' => ['required', 'string', 'min:2', 'max:100'],
            'address' => ['required', 'string', 'min:5', 'max:500'],
            'phone' => ['required', 'string', 'max:20', 'regex:/^(?=(?:\D*\d){7})\+?[0-9 ()-]{7,20}$/'],
            'logo' => ['nullable', 'string', 'max:2796204'],
        ]);
        $logo = null;
        $extension = null;
        if (! empty($data['logo'])) {
            $logo = base64_decode($data['logo'], true);
            $image = $logo === false ? false : @getimagesizefromstring($logo);
            $extension = $image ? ([IMAGETYPE_PNG => 'png', IMAGETYPE_JPEG => 'jpg', IMAGETYPE_WEBP => 'webp'][$image[2]] ?? null) : null;
            if (! $extension || strlen($logo) > 2097152 || $image[0] > 4096 || $image[1] > 4096) {
                throw ValidationException::withMessages(['logo' => 'Choose a PNG, JPEG or WebP logo up to 2 MiB and 4096 pixels.']);
            }
        }

        $user = $request->user();
        $disk = Storage::disk('public');
        $path = null;
        $created = false;
        try {
            $facility = DB::transaction(function () use ($data, $user, $logo, $extension, $request, $disk, &$path, &$created): Facility {
                User::whereKey($user->id)->lockForUpdate()->firstOrFail();
                $existing = Facility::where('data->registration_request', $data['request_id'])
                    ->where('data->registered_by', $user->id)->first();
                if ($existing) {
                    return $existing;
                }
                if ($logo !== null) {
                    $path = 'facility-logos/'.Str::uuid().'.'.$extension;
                    abort_unless($disk->put($path, $logo), 503, 'Could not save your logo. Please try again.');
                }
                $facility = Facility::create([
                    ...collect($data)->only(['name', 'type', 'state', 'lga', 'address', 'phone'])->all(),
                    'status' => 'pending', 'email' => $user->email,
                    'registration_request' => $data['request_id'], 'registered_by' => $user->id,
                    'logo_path' => $path,
                    'tenancy_create_database' => false, 'registration_provisioned' => false,
                ]);
                FacilityMembership::create([
                    'user_id' => $user->id, 'facility_id' => $facility->id, 'role' => 'admin', 'status' => 'active',
                ]);
                AuditLog::create([
                    'actor_type' => 'staff', 'actor_id' => $user->id, 'facility_id' => $facility->id,
                    'action' => 'facility.registered', 'ip_address' => $request->ip(), 'occurred_at' => now(),
                ]);
                $created = true;

                return $facility;
            });
        } catch (Throwable $e) {
            if ($path) {
                $disk->delete($path);
            }
            throw $e;
        }
        if ($created && ($to = config('nhc.enquiries_to'))) {
            try {
                Mail::to($to)->send(new FacilityRegistered($facility, $user, $data));
            } catch (Throwable $e) {
                report($e);
            }
        }

        $provisioning->ensureReady($facility);

        return response()->json([
            'facility_id' => $facility->id, 'name' => $facility->name, 'status' => $facility->status,
            ...FacilityTypes::describe($facility),
            'logo_url' => $facility->logo_path ? Storage::disk('public')->url($facility->logo_path) : null,
        ], $created ? 201 : 200);
    }
}
