<?php

namespace App\Modules\ClinicalRecords\Http;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Facility;
use App\Models\User;
use App\Modules\ClinicalRecords\ClinicalAccess;
use App\Modules\ClinicalRecords\Models\Allergy;
use App\Modules\ClinicalRecords\RecordsView;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * The patient's side, in the Hospify Citizen app: their whole record from every
 * hospital, allergies they add themselves, and who has looked at their record.
 */
class PatientRecordsController extends Controller
{
    public function __construct(private ClinicalAccess $access, private RecordsView $view) {}

    public function index(Request $request)
    {
        $patient = $request->user();
        $data = $request->validate(['before' => ['nullable', 'date']]);
        $reach = $this->access->forPatient($patient);
        $this->access->logRead($reach, $patient, 'patient', $patient->id, null, $request->ip());

        $full = $this->view->full($reach, $patient, $data['before'] ?? null);
        unset($full['sources']);

        return $full;
    }

    public function summary(Request $request)
    {
        $patient = $request->user();

        return $this->view->summary($this->access->forPatient($patient), $patient);
    }

    /** Shown as added by the patient until a clinician confirms it. */
    public function addAllergy(Request $request)
    {
        $patient = $request->user();
        $data = $request->validate([
            'substance' => ['required', 'string', 'max:150'],
            'reaction' => ['nullable', 'string', 'max:255'],
            'severity' => ['nullable', Rule::in(Allergy::SEVERITIES)],
        ]);

        $row = Allergy::create([
            'patient_id' => $patient->id, 'facility_id' => null, 'recorded_by' => null, 'recorded_at' => now(),
            'substance' => $data['substance'], 'reaction' => $data['reaction'] ?? null,
            'severity' => $data['severity'] ?? 'unknown', 'source' => 'patient',
        ]);

        return response()->json($this->view->allergy($row), 201);
    }

    /** Every time staff opened this record: who, where, when, and how they had access. */
    public function access(Request $request)
    {
        $rows = AuditLog::where('patient_id', $request->user()->id)->where('actor_type', 'staff')
            ->whereIn('action', ['records.viewed', 'records.emergency_access'])
            ->latest('occurred_at')->limit(200)->get();

        $names = User::whereIn('id', $rows->pluck('actor_id')->unique())->pluck('name', 'id');
        $hospitals = Facility::whereIn('id', $rows->pluck('facility_id')->unique())->pluck('name', 'id');

        return $rows->map(fn (AuditLog $r) => [
            'at' => $r->occurred_at->toIso8601String(),
            'who' => $names[$r->actor_id] ?? 'Hospital staff',
            'hospital' => $hospitals[$r->facility_id] ?? null,
            'emergency' => $r->action === 'records.emergency_access',
            'how' => $this->how($r),
            'reason' => $r->action === 'records.emergency_access' ? $r->reason : null,
        ])->values();
    }

    private function how(AuditLog $r): string
    {
        if ($r->action === 'records.emergency_access') {
            return 'Emergency access';
        }
        $via = (string) $r->reason;

        return match (true) {
            str_contains($via, 'emergency') => 'Emergency access',
            str_contains($via, 'request:') => 'A request you approved',
            default => 'As one of your hospitals',
        };
    }
}
