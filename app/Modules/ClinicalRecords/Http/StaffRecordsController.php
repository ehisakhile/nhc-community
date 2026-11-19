<?php

namespace App\Modules\ClinicalRecords\Http;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Concerns\FacilityScope;
use App\Models\Facility;
use App\Models\Patient;
use App\Modules\ClinicalRecords\ClinicalAccess;
use App\Modules\ClinicalRecords\Models\EmergencyAccess;
use App\Modules\ClinicalRecords\Models\Prescription;
use App\Modules\ClinicalRecords\Models\Visit;
use App\Modules\ClinicalRecords\RecordsView;
use App\Modules\Patients\PatientProfile;
use App\Services\Termii;
use Illuminate\Http\Request;
use Throwable;

/** Reading a patient's record at a hospital: what this hospital wrote, plus what other hospitals have shared. */
class StaffRecordsController extends Controller
{
    use ClinicalStaff;

    private const READERS = ['records.read', 'prescriptions.read', 'lab.read', 'imaging.read'];

    public function __construct(private ClinicalAccess $access, private RecordsView $view) {}

    public function show(Request $request, Facility $facility, string $nhcId)
    {
        $member = $this->memberWith($request, $facility, self::READERS, "Your role can't read clinical records.");
        $patient = $this->patientHere($facility, $nhcId);
        $data = $request->validate(['before' => ['nullable', 'date']]);

        $reach = $this->access->forStaff($member, $facility, $patient);
        $this->access->logRead($reach, $patient, 'staff', $member->user_id, $facility->id, $request->ip());

        return ['patient' => PatientProfile::of($patient), ...$this->view->full($reach, $patient, $data['before'] ?? null)];
    }

    public function summary(Request $request, Facility $facility, string $nhcId)
    {
        $member = $this->memberWith($request, $facility, self::READERS, "Your role can't read clinical records.");
        $patient = $this->patientHere($facility, $nhcId);

        $reach = $this->access->forStaff($member, $facility, $patient);
        abort_unless($reach->can('summary'), 403, "Your role can't read the summary.");
        $this->access->logRead($reach, $patient, 'staff', $member->user_id, $facility->id, $request->ip());

        return $this->view->summary($reach, $patient);
    }

    /**
     * Break-glass, for a patient who can't consent: the summary from every
     * hospital for a day. Works even if they haven't joined this hospital.
     * Logged, and the patient is told by SMS.
     */
    public function emergency(Request $request, Facility $facility, string $nhcId, Termii $sms)
    {
        $member = $this->memberWith($request, $facility, ['records.read'], 'Only clinicians can use emergency access.');
        $data = $request->validate(['reason' => ['required', 'string', 'min:10', 'max:500']]);
        $patient = Patient::where('nhc_id', strtoupper($nhcId))->first();
        abort_unless($patient, 404, 'No patient has that NHC ID.');

        $grant = EmergencyAccess::create([
            'patient_id' => $patient->id, 'facility_id' => $facility->id, 'user_id' => $member->user_id,
            'reason' => $data['reason'], 'expires_at' => now()->addHours(EmergencyAccess::HOURS),
        ]);
        AuditLog::create([
            'actor_type' => 'staff', 'actor_id' => $member->user_id, 'facility_id' => $facility->id, 'patient_id' => $patient->id,
            'action' => 'records.emergency_access', 'target_type' => 'emergency_access', 'target_id' => $grant->id,
            'reason' => $data['reason'], 'ip_address' => $request->ip(), 'occurred_at' => now(),
        ]);

        if ($phone = $patient->otpPhone()) {
            try {
                $sms->send($phone, "NHC: In an emergency, {$facility->name} opened your health summary (allergies, conditions, medicines, blood group). You can see who and why in the Hospify app.");
                $grant->forceFill(['patient_told_at' => now()])->save();
            } catch (Throwable $e) {
                report($e); // access stands; the log and the app still show it
            }
        }

        $reach = $this->access->forStaff($member, $facility, $patient);
        $this->access->logRead($reach, $patient, 'staff', $member->user_id, $facility->id, $request->ip());

        return [
            'patient' => PatientProfile::preview($patient),
            'expires_at' => $grant->expires_at->toIso8601String(),
            'summary' => $this->view->summary($reach, $patient),
        ];
    }

    /** Today's work: this hospital's visits, open by default. */
    public function visits(Request $request, Facility $facility)
    {
        $this->memberWith($request, $facility, [...self::READERS, 'vitals.write'], "Your role can't see visits.");
        $data = $request->validate(['status' => ['nullable', 'in:open,closed,all']]);
        $status = $data['status'] ?? 'open';

        return Visit::withoutGlobalScope(FacilityScope::class)->where('facility_id', $facility->id)
            ->when($status !== 'all', fn ($q) => $q->where('status', $status))
            ->with(['patient', 'author'])->latest('recorded_at')->limit(100)->get()
            ->map(fn (Visit $v) => [
                ...$this->view->visit($v),
                'patient' => ['nhc_id' => $v->patient->nhc_id, 'name' => trim($v->patient->first_name.' '.$v->patient->last_name)],
            ])->values();
    }

    /** The pharmacy's queue: this hospital's active prescriptions not yet dispensed. */
    public function prescriptions(Request $request, Facility $facility)
    {
        $this->memberWith($request, $facility, ['prescriptions.read', 'prescriptions.dispense', 'prescriptions.write'], "Your role can't see prescriptions.");
        $data = $request->validate(['status' => ['nullable', 'in:pending,dispensed']]);
        $dispensed = ($data['status'] ?? 'pending') === 'dispensed';

        return Prescription::withoutGlobalScope(FacilityScope::class)->where('facility_id', $facility->id)
            ->whereNull('voided_at')
            ->when($dispensed, fn ($q) => $q->whereNotNull('dispensed_at')->latest('dispensed_at'),
                fn ($q) => $q->where('status', 'active')->whereNull('dispensed_at')->oldest('recorded_at'))
            ->with(['patient', 'author', 'facility'])->limit(200)->get()
            ->map(fn (Prescription $p) => [
                ...$this->view->prescription($p),
                'patient' => ['nhc_id' => $p->patient->nhc_id, 'name' => trim($p->patient->first_name.' '.$p->patient->last_name)],
            ])->values();
    }
}
