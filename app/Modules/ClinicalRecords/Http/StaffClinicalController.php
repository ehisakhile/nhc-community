<?php

namespace App\Modules\ClinicalRecords\Http;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Concerns\FacilityScope;
use App\Models\Facility;
use App\Models\FacilityMembership;
use App\Models\Patient;
use App\Modules\ClinicalRecords\ClinicalAccess;
use App\Modules\ClinicalRecords\Models\Allergy;
use App\Modules\ClinicalRecords\Models\ClinicalNote;
use App\Modules\ClinicalRecords\Models\Diagnosis;
use App\Modules\ClinicalRecords\Models\PatientFact;
use App\Modules\ClinicalRecords\Models\Prescription;
use App\Modules\ClinicalRecords\Models\Vaccination;
use App\Modules\ClinicalRecords\Models\Visit;
use App\Modules\ClinicalRecords\Models\Vital;
use App\Modules\ClinicalRecords\RecordsView;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Writing to a patient's record at this hospital. Every row is stamped with the
 * hospital and the author. Nothing is edited: notes are amended by adding to
 * them, and a wrong row is marked "entered in error" with a reason.
 */
class StaffClinicalController extends Controller
{
    use ClinicalStaff;

    private const VOIDABLE = [
        'vitals' => Vital::class, 'notes' => ClinicalNote::class, 'diagnoses' => Diagnosis::class,
        'allergies' => Allergy::class, 'prescriptions' => Prescription::class,
        'vaccinations' => Vaccination::class, 'facts' => PatientFact::class,
    ];

    public function __construct(private ClinicalAccess $access, private RecordsView $view) {}

    // --- visits ---------------------------------------------------------------

    public function openVisit(Request $request, Facility $facility, string $nhcId)
    {
        [$member, $patient] = $this->context($request, $facility, $nhcId, ['records.write', 'vitals.write']);
        $data = $request->validate([
            'type' => ['required', Rule::in(['outpatient', 'emergency', 'inpatient', 'telehealth'])],
            'reason' => ['nullable', 'string', 'max:255'],
        ]);

        $visit = Visit::create($this->stamp($member, $facility, $patient) + $data);

        return response()->json($this->view->visit($visit->load(['facility', 'author'])), 201);
    }

    public function closeVisit(Request $request, Facility $facility, string $nhcId, string $visitId)
    {
        [$member, $patient] = $this->context($request, $facility, $nhcId, ['records.write']);
        $visit = $this->openVisitOf($facility, $patient, $visitId);
        $visit->update(['status' => 'closed', 'closed_at' => now(), 'closed_by' => $member->user_id]);

        return $this->view->visit($visit->load(['facility', 'author']));
    }

    public function vitals(Request $request, Facility $facility, string $nhcId, string $visitId)
    {
        [$member, $patient] = $this->context($request, $facility, $nhcId, ['vitals.write', 'records.write']);
        $visit = $this->openVisitOf($facility, $patient, $visitId);
        $data = $request->validate([
            'temperature_c' => ['nullable', 'numeric', 'between:25,45'],
            'pulse_bpm' => ['nullable', 'integer', 'between:20,250'],
            'respiratory_rate' => ['nullable', 'integer', 'between:4,80'],
            'bp_systolic' => ['nullable', 'integer', 'between:50,260', 'required_with:bp_diastolic'],
            'bp_diastolic' => ['nullable', 'integer', 'between:20,180', 'required_with:bp_systolic'],
            'spo2' => ['nullable', 'integer', 'between:40,100'],
            'weight_kg' => ['nullable', 'numeric', 'between:0.3,400'],
            'height_cm' => ['nullable', 'numeric', 'between:20,250'],
            'note' => ['nullable', 'string', 'max:255'],
        ]);
        abort_if(collect($data)->except('note')->filter(fn ($v) => $v !== null)->isEmpty(), 422, 'Enter at least one reading.');

        $row = Vital::create($this->stamp($member, $facility, $patient) + ['visit_id' => $visit->id] + $data);

        return response()->json($this->view->vital($row->load(['facility', 'author'])), 201);
    }

    public function note(Request $request, Facility $facility, string $nhcId, string $visitId)
    {
        [$member, $patient] = $this->context($request, $facility, $nhcId, ['records.write']);
        $visit = $this->visitOf($facility, $patient, $visitId);
        $data = $request->validate([
            'kind' => ['required', Rule::in(ClinicalNote::KINDS)],
            'body' => ['required', 'string', 'min:2', 'max:20000'],
            'amends_id' => ['nullable', 'string'],
        ]);

        if (! empty($data['amends_id'])) {
            // An amendment adds to a note this hospital wrote in the same visit.
            $original = $this->access->ownRow(ClinicalNote::class, $facility, $patient, $data['amends_id']);
            abort_unless($original->visit_id === $visit->id, 422, 'An amendment must be in the same visit as the note it corrects.');
        } else {
            abort_if($visit->status === 'closed', 422, 'This visit is closed. Add an amendment to an existing note instead.');
        }

        $row = ClinicalNote::create($this->stamp($member, $facility, $patient) + ['visit_id' => $visit->id] + $data);

        return response()->json($this->view->note($row->load(['facility', 'author'])), 201);
    }

    // --- diagnoses, allergies, facts -------------------------------------------

    public function diagnosis(Request $request, Facility $facility, string $nhcId)
    {
        [$member, $patient] = $this->context($request, $facility, $nhcId, ['records.write']);
        $data = $request->validate([
            'visit_id' => ['nullable', 'string'],
            'code' => ['nullable', 'string', 'max:10', 'regex:/^[A-Z][0-9][0-9A-Z](\.[0-9A-Z]{1,4})?$/i'],
            'description' => ['required', 'string', 'max:255'],
            'chronic' => ['boolean'],
        ]);
        if (! empty($data['visit_id'])) {
            $this->visitOf($facility, $patient, $data['visit_id']);
        }

        $row = Diagnosis::create(array_merge($this->stamp($member, $facility, $patient), $data, ['code' => isset($data['code']) ? strtoupper($data['code']) : null]));

        return response()->json($this->view->diagnosis($row->load(['facility', 'author'])), 201);
    }

    public function resolveDiagnosis(Request $request, Facility $facility, string $nhcId, string $id)
    {
        [, $patient] = $this->context($request, $facility, $nhcId, ['records.write']);
        $row = $this->access->ownRow(Diagnosis::class, $facility, $patient, $id);
        abort_if($row->isVoided(), 422, 'This was entered in error.');
        $row->update(['status' => 'resolved', 'resolved_at' => now()]);

        return $this->view->diagnosis($row->load(['facility', 'author']));
    }

    public function allergy(Request $request, Facility $facility, string $nhcId)
    {
        [$member, $patient] = $this->context($request, $facility, $nhcId, ['records.write', 'vitals.write']);
        $data = $request->validate([
            'substance' => ['required', 'string', 'max:150'],
            'reaction' => ['nullable', 'string', 'max:255'],
            'severity' => ['required', Rule::in(Allergy::SEVERITIES)],
        ]);

        $row = Allergy::create($this->stamp($member, $facility, $patient) + $data + ['source' => 'clinician']);

        return response()->json($this->view->allergy($row->load(['facility', 'author'])), 201);
    }

    /** Mark an allergy this hospital recorded as no longer active, or confirm one the patient added. */
    public function updateAllergy(Request $request, Facility $facility, string $nhcId, string $id)
    {
        [$member, $patient] = $this->context($request, $facility, $nhcId, ['records.write']);
        $data = $request->validate(['action' => ['required', Rule::in(['inactive', 'confirm'])]]);

        if ($data['action'] === 'confirm') {
            $row = Allergy::withoutGlobalScope(FacilityScope::class)->where(['patient_id' => $patient->id, 'source' => 'patient'])
                ->whereNull('facility_id')->findOrFail($id);
            $row->update(['confirmed_by' => $member->user_id, 'confirmed_at' => now()]);
        } else {
            $row = $this->access->ownRow(Allergy::class, $facility, $patient, $id);
            $row->update(['status' => 'inactive']);
        }

        return $this->view->allergy($row->load(['facility', 'author']));
    }

    public function fact(Request $request, Facility $facility, string $nhcId)
    {
        [$member, $patient] = $this->context($request, $facility, $nhcId, ['records.write', 'lab.results']);
        $kind = $request->validate(['kind' => ['required', Rule::in(array_keys(PatientFact::KINDS))]])['kind'];
        $value = $request->validate(['value' => ['required', Rule::in(PatientFact::KINDS[$kind])]])['value'];

        $row = PatientFact::create($this->stamp($member, $facility, $patient) + ['kind' => $kind, 'value' => $value]);

        return response()->json($this->view->fact($row->load(['facility', 'author'])) + ['kind' => $kind], 201);
    }

    public function vaccination(Request $request, Facility $facility, string $nhcId)
    {
        [$member, $patient] = $this->context($request, $facility, $nhcId, ['records.write', 'vitals.write']);
        $data = $request->validate([
            'vaccine' => ['required', 'string', 'max:150'],
            'dose_number' => ['nullable', 'integer', 'between:1,10'],
            'given_on' => ['required', 'date', 'before_or_equal:today'],
            'batch' => ['nullable', 'string', 'max:50'],
        ]);

        $row = Vaccination::create($this->stamp($member, $facility, $patient) + $data);

        return response()->json(['id' => $row->id, ...$row->only(['vaccine', 'dose_number', 'batch']), 'given_on' => $row->given_on->toDateString()], 201);
    }

    // --- prescriptions -----------------------------------------------------------

    public function prescribe(Request $request, Facility $facility, string $nhcId, string $visitId)
    {
        [$member, $patient] = $this->context($request, $facility, $nhcId, ['prescriptions.write'], 'Only prescribers can prescribe.');
        $visit = $this->openVisitOf($facility, $patient, $visitId);
        $data = $request->validate([
            'drug' => ['required', 'string', 'max:150'],
            'strength' => ['nullable', 'string', 'max:50'],
            'form' => ['nullable', 'string', 'max:30'],
            'dose' => ['required', 'string', 'max:50'],
            'route' => ['nullable', 'string', 'max:30'],
            'frequency' => ['required', 'string', 'max:50'],
            'duration_days' => ['nullable', 'integer', 'between:1,365'],
            'quantity' => ['nullable', 'string', 'max:30'],
            'instructions' => ['nullable', 'string', 'max:255'],
        ]);

        $row = Prescription::create($this->stamp($member, $facility, $patient) + ['visit_id' => $visit->id] + $data);

        return response()->json($this->view->prescription($row->load(['facility', 'author'])), 201);
    }

    public function stopPrescription(Request $request, Facility $facility, string $nhcId, string $id)
    {
        [, $patient] = $this->context($request, $facility, $nhcId, ['prescriptions.write'], 'Only prescribers can stop a prescription.');
        $data = $request->validate(['reason' => ['required', 'string', 'max:255'], 'completed' => ['boolean']]);
        $row = $this->access->ownRow(Prescription::class, $facility, $patient, $id);
        abort_unless($row->status === 'active' && ! $row->isVoided(), 422, "This prescription isn't active.");

        $row->update(['status' => ($data['completed'] ?? false) ? 'completed' : 'stopped', 'stopped_at' => now(), 'stop_reason' => $data['reason']]);

        return $this->view->prescription($row->load(['facility', 'author']));
    }

    /** Pharmacy at this hospital hands it over. */
    public function dispense(Request $request, Facility $facility, string $nhcId, string $id)
    {
        [$member, $patient] = $this->context($request, $facility, $nhcId, ['prescriptions.dispense'], 'Only the pharmacy can dispense.');
        $row = $this->access->ownRow(Prescription::class, $facility, $patient, $id);
        abort_unless($row->status === 'active' && ! $row->isVoided(), 422, "This prescription isn't active.");
        abort_if($row->dispensed_at, 422, 'This prescription has already been dispensed.');

        $row->update(['dispensed_by' => $member->user_id, 'dispensed_at' => now()]);

        return $this->view->prescription($row->load(['facility', 'author']));
    }

    // --- entered in error ----------------------------------------------------------

    public function void(Request $request, Facility $facility, string $nhcId, string $kind, string $id)
    {
        abort_unless(isset(self::VOIDABLE[$kind]), 404);
        $perms = $kind === 'prescriptions' ? ['prescriptions.write'] : ['records.write'];
        [$member, $patient] = $this->context($request, $facility, $nhcId, $perms);
        $data = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:255']]);

        $row = $this->access->ownRow(self::VOIDABLE[$kind], $facility, $patient, $id);
        abort_if($row->isVoided(), 422, 'This is already marked as entered in error.');
        $row->update(['voided_at' => now(), 'voided_by' => $member->user_id, 'void_reason' => $data['reason']]);

        AuditLog::create([
            'actor_type' => 'staff', 'actor_id' => $member->user_id, 'facility_id' => $facility->id, 'patient_id' => $patient->id,
            'action' => 'records.entered_in_error', 'target_type' => $kind, 'target_id' => $row->id,
            'reason' => $data['reason'], 'ip_address' => $request->ip(), 'occurred_at' => now(),
        ]);

        return ['id' => $row->id, 'entered_in_error' => ['at' => $row->voided_at->toIso8601String(), 'reason' => $row->void_reason]];
    }

    // --- helpers ----------------------------------------------------------------------

    /** @return array{0: FacilityMembership, 1: Patient} */
    private function context(Request $request, Facility $facility, string $nhcId, array $anyOf, string $message = "Your role can't do that here."): array
    {
        $member = $this->memberWith($request, $facility, $anyOf, $message);

        return [$member, $this->patientHere($facility, $nhcId)];
    }

    private function stamp(FacilityMembership $member, Facility $facility, Patient $patient): array
    {
        return ['patient_id' => $patient->id, 'facility_id' => $facility->id, 'recorded_by' => $member->user_id, 'recorded_at' => now()];
    }

    private function visitOf(Facility $facility, Patient $patient, string $id): Visit
    {
        return $this->access->ownRow(Visit::class, $facility, $patient, $id);
    }

    private function openVisitOf(Facility $facility, Patient $patient, string $id): Visit
    {
        $visit = $this->visitOf($facility, $patient, $id);
        abort_if($visit->status === 'closed', 422, 'This visit is closed.');

        return $visit;
    }
}
