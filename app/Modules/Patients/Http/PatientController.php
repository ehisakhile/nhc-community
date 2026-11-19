<?php

namespace App\Modules\Patients\Http;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Concerns\FacilityScope;
use App\Models\Facility;
use App\Models\Patient;
use App\Models\PatientFacilityLink;
use App\Modules\Patients\FacilityJoining;
use App\Modules\Patients\PatientProfile;
use App\Modules\StaffAccess;
use App\Services\OneTimeCodes;
use App\Services\Termii;
use App\Support\NigerianPhone;
use Illuminate\Http\Request;
use InvalidArgumentException;

/**
 * A hospital's own patients, and onboarding someone already on NHC. They keep
 * their NHC ID and basic profile; nothing is typed in again, and no clinical
 * record comes with them (that needs Record sharing and their consent).
 */
class PatientController extends Controller
{
    use StaffAccess;

    /** This hospital's patients, newest first. */
    public function index(Request $request, Facility $facility)
    {
        $this->requirePermission($request, $facility, 'patients.view');
        $search = trim((string) $request->query('search', ''));

        $links = PatientFacilityLink::withoutGlobalScope(FacilityScope::class)
            ->where('facility_id', $facility->id)
            ->with('patient')
            ->when($search !== '', fn ($q) => $q->whereHas('patient', function ($p) use ($search) {
                $p->where('nhc_id', strtoupper($search))
                    ->orWhere('first_name', 'like', "%{$search}%")
                    ->orWhere('last_name', 'like', "%{$search}%");
            }))
            ->latest('confirmed_at')
            ->limit(100)
            ->get();

        return $links->map(fn ($l) => PatientProfile::of($l->patient, $l->confirmed_at))->values();
    }

    public function show(Request $request, Facility $facility, string $nhcId)
    {
        $this->requirePermission($request, $facility, 'patients.view');
        $link = $this->linkHere($facility, $nhcId) ?? abort(404, 'Not a patient at this hospital.');

        return PatientProfile::of($link->patient, $link->confirmed_at);
    }

    /** By NHC ID or phone. Full profile if they're already here; a masked preview if they're elsewhere. */
    public function find(Request $request, Facility $facility)
    {
        $member = $this->requirePermission($request, $facility, 'patients.onboard', "You can't onboard patients at this hospital.");
        $data = $request->validate(['lookup' => ['required', 'string', 'max:30']]);

        $patient = $this->lookup($data['lookup']);
        AuditLog::create([
            'actor_type' => 'staff', 'actor_id' => $member->user_id, 'facility_id' => $facility->id, 'patient_id' => $patient?->id,
            'action' => 'patient.looked_up', 'ip_address' => $request->ip(), 'occurred_at' => now(),
        ]);

        if (! $patient) {
            return ['found' => false];
        }

        $link = $this->linkHere($facility, $patient->nhc_id);

        return $link
            ? ['found' => true, 'here' => true, 'patient' => PatientProfile::of($patient, $link->confirmed_at)]
            : ['found' => true, 'here' => false, 'preview' => PatientProfile::preview($patient)];
    }

    /** Texts the patient a code: entering it is their consent to join this hospital. */
    public function join(Request $request, Facility $facility, OneTimeCodes $codes, Termii $sms)
    {
        $this->requirePermission($request, $facility, 'patients.onboard', "You can't onboard patients at this hospital.");
        $data = $request->validate(['nhc_id' => ['required', 'string', 'max:24']]);

        $patient = $this->lookup($data['nhc_id']) ?? abort(404, 'No NHC record with that ID.');
        abort_if($this->linkHere($facility, $patient->nhc_id), 409, 'Already a patient at this hospital.');

        $codes->throttle('join', $patient->nhc_id);
        [$record, $plain] = $codes->issue('join', 'sms', $request->user(), ['facility_id' => $facility->id, 'patient_id' => $patient->id]);
        $sms->send($patient->phone, "{$plain} is your NHC code to join {$facility->name}. Only give it to the hospital staff in front of you.");

        return ['challenge' => $record->id, 'phone_hint' => PatientProfile::preview($patient)['phone_hint'], 'expires_in' => OneTimeCodes::TTL_MINUTES * 60];
    }

    public function confirmJoin(Request $request, Facility $facility, OneTimeCodes $codes, FacilityJoining $joining)
    {
        $this->requirePermission($request, $facility, 'patients.onboard', "You can't onboard patients at this hospital.");
        $data = $request->validate(['challenge' => ['required', 'string'], 'code' => ['required', 'string', 'max:10']]);

        $code = $codes->verify($data['challenge'], $data['code'], 'join');
        abort_unless($code->subject_id === $request->user()->id && $code->payload['facility_id'] === $facility->id, 422, 'That code is wrong or has expired.');

        $patient = Patient::findOrFail($code->payload['patient_id']);
        $link = $joining->link($patient, $facility, $request->user(), $request->ip());

        return response()->json(PatientProfile::of($patient, $link->confirmed_at), 201);
    }

    /** Account holders only (dependants join with their guardian). Past phone numbers match nobody. */
    private function lookup(string $value): ?Patient
    {
        $value = strtoupper(trim($value));
        $query = Patient::query()->whereNull('guardian_id')->whereNotNull('phone');

        if (str_starts_with($value, 'NHC-')) {
            return $query->where('nhc_id', $value)->first();
        }

        try {
            return $query->where('phone', NigerianPhone::normalise($value))->first();
        } catch (InvalidArgumentException) {
            return null;
        }
    }

    private function linkHere(Facility $facility, string $nhcId): ?PatientFacilityLink
    {
        return PatientFacilityLink::withoutGlobalScope(FacilityScope::class)
            ->where('facility_id', $facility->id)
            ->whereHas('patient', fn ($p) => $p->where('nhc_id', strtoupper($nhcId)))
            ->with('patient')
            ->first();
    }
}
