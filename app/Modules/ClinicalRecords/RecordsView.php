<?php

namespace App\Modules\ClinicalRecords;

use App\Models\Facility;
use App\Models\Patient;
use App\Modules\ClinicalRecords\Models\Allergy;
use App\Modules\ClinicalRecords\Models\ClinicalNote;
use App\Modules\ClinicalRecords\Models\Diagnosis;
use App\Modules\ClinicalRecords\Models\PatientFact;
use App\Modules\ClinicalRecords\Models\Prescription;
use App\Modules\ClinicalRecords\Models\Vaccination;
use App\Modules\ClinicalRecords\Models\Visit;
use App\Modules\ClinicalRecords\Models\Vital;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/** Shapes what a Reach allows into a summary and a timeline of visits. Reads only through ClinicalAccess. */
class RecordsView
{
    public const NOT_BUILT = ['lab_results', 'imaging'];

    private array $hospitals = [];

    public function __construct(private ClinicalAccess $access) {}

    public function full(Reach $reach, Patient $patient, ?string $before = null, int $limit = 30): array
    {
        return [
            'summary' => $reach->can('summary') ? $this->summary($reach, $patient) : null,
            'visits' => $this->visits($reach, $patient, $before, $limit),
            'vaccinations' => $reach->can('vaccinations') ? $this->vaccinations($reach, $patient) : [],
            'not_available_yet' => array_values(array_filter(self::NOT_BUILT, fn ($t) => $reach->can($t))),
            'sources' => $this->sources($reach),
        ];
    }

    /** Allergies, conditions, current medicines, blood group and genotype. */
    public function summary(Reach $reach, Patient $patient): array
    {
        $q = fn (string $model) => $this->access->query($reach, $model, $patient, 'summary')->whereNull('voided_at')->with(['facility', 'author']);

        $facts = $q(PatientFact::class)->latest('recorded_at')->get()->unique('kind')->keyBy('kind');

        return [
            'allergies' => $q(Allergy::class)->where('status', 'active')->latest('recorded_at')->get()->map(fn ($a) => $this->allergy($a))->values(),
            'conditions' => $q(Diagnosis::class)->where('status', 'active')->latest('recorded_at')->get()->map(fn ($d) => $this->diagnosis($d))->values(),
            'current_medicines' => $q(Prescription::class)->where('status', 'active')->latest('recorded_at')->get()->map(fn ($p) => $this->prescription($p))->values(),
            'blood_group' => isset($facts['blood_group']) ? $this->fact($facts['blood_group']) : null,
            'genotype' => isset($facts['genotype']) ? $this->fact($facts['genotype']) : null,
        ];
    }

    private function visits(Reach $reach, Patient $patient, ?string $before, int $limit): array
    {
        $shellTypes = array_filter(['consultations', 'prescriptions', 'discharge_summaries'], fn ($t) => $reach->can($t));
        if (! $shellTypes) {
            return [];
        }

        // A visit shows if the reader may see anything recorded in it.
        $ids = collect($shellTypes)->flatMap(fn ($t) => $this->access->query($reach, Visit::class, $patient, $t)->pluck('id'))->unique();
        $visits = Visit::withoutGlobalScopes()->whereIn('id', $ids)
            ->when($before, fn ($q) => $q->where('recorded_at', '<', $before))
            ->with(['facility', 'author'])->latest('recorded_at')->limit($limit)->get();
        if ($visits->isEmpty()) {
            return [];
        }

        $in = fn (string $model, string $type) => $reach->can($type)
            ? $this->access->query($reach, $model, $patient, $type)->whereIn('visit_id', $visits->pluck('id'))->with(['facility', 'author'])->oldest('recorded_at')->get()->groupBy('visit_id')
            : collect();

        $vitals = $in(Vital::class, 'consultations');
        $diagnoses = $in(Diagnosis::class, 'consultations');
        $prescriptions = $in(Prescription::class, 'prescriptions');
        $notes = $in(ClinicalNote::class, 'consultations')->map->where('kind', '!=', 'discharge_summary');
        $discharge = $in(ClinicalNote::class, 'discharge_summaries')->map->where('kind', 'discharge_summary');
        $amendedBy = ClinicalNote::withoutGlobalScopes()->whereIn('amends_id', $visits->isEmpty() ? [] : $notes->flatten()->merge($discharge->flatten())->pluck('id'))
            ->get(['id', 'amends_id'])->groupBy('amends_id');

        return $visits->map(fn (Visit $v) => [
            ...$this->base($v),
            'type' => $v->type,
            'reason' => $v->reason,
            'status' => $v->status,
            'closed_at' => $v->closed_at?->toIso8601String(),
            'vitals' => ($vitals[$v->id] ?? collect())->map(fn ($x) => $this->vital($x))->values(),
            'notes' => ($notes[$v->id] ?? collect())->merge($discharge[$v->id] ?? collect())->sortBy('recorded_at')
                ->map(fn ($n) => $this->note($n, $amendedBy[$n->id] ?? collect()))->values(),
            'diagnoses' => ($diagnoses[$v->id] ?? collect())->map(fn ($d) => $this->diagnosis($d))->values(),
            'prescriptions' => ($prescriptions[$v->id] ?? collect())->map(fn ($p) => $this->prescription($p))->values(),
        ])->values()->all();
    }

    private function vaccinations(Reach $reach, Patient $patient): Collection
    {
        return $this->access->query($reach, Vaccination::class, $patient, 'vaccinations')
            ->with(['facility', 'author'])->latest('given_on')->limit(200)->get()
            ->map(fn (Vaccination $v) => [...$this->base($v), 'vaccine' => $v->vaccine, 'dose_number' => $v->dose_number, 'given_on' => $v->given_on->toDateString(), 'batch' => $v->batch])
            ->values();
    }

    private function sources(Reach $reach): array
    {
        $ids = array_filter(array_column($reach->sources(), 'hospital_id'), fn ($id) => $id !== '*');
        $names = Facility::whereIn('id', $ids)->pluck('name', 'id');

        return array_map(fn ($s) => $s + ['hospital_name' => $s['hospital_id'] === '*' ? 'Every hospital' : ($names[$s['hospital_id']] ?? null)], $reach->sources());
    }

    // --- shapes -------------------------------------------------------------

    public function base(Model $row): array
    {
        return [
            'id' => $row->id,
            'hospital' => $row->facility ? ['id' => $row->facility->id, 'name' => $row->facility->name] : null,
            'recorded_by' => $row->author?->name,
            'recorded_at' => $row->recorded_at?->toIso8601String(),
            'entered_in_error' => $row->voided_at ? ['at' => $row->voided_at->toIso8601String(), 'reason' => $row->void_reason] : null,
        ];
    }

    public function vital(Vital $v): array
    {
        return [...$this->base($v), ...$v->only(['temperature_c', 'pulse_bpm', 'respiratory_rate', 'bp_systolic', 'bp_diastolic', 'spo2', 'weight_kg', 'height_cm', 'note'])];
    }

    public function note(ClinicalNote $n, Collection $amendedBy = new Collection): array
    {
        return [...$this->base($n), 'kind' => $n->kind, 'body' => $n->body, 'amends' => $n->amends_id, 'amended_by' => $amendedBy->pluck('id')->values()];
    }

    public function diagnosis(Diagnosis $d): array
    {
        return [...$this->base($d), 'visit_id' => $d->visit_id, 'code' => $d->code, 'description' => $d->description, 'chronic' => $d->chronic, 'status' => $d->status, 'resolved_at' => $d->resolved_at?->toIso8601String()];
    }

    public function allergy(Allergy $a): array
    {
        return [...$this->base($a), 'substance' => $a->substance, 'reaction' => $a->reaction, 'severity' => $a->severity, 'status' => $a->status,
            'source' => $a->source, 'confirmed' => $a->source === 'clinician' || $a->confirmed_at !== null];
    }

    public function prescription(Prescription $p): array
    {
        return [...$this->base($p), 'visit_id' => $p->visit_id,
            ...$p->only(['drug', 'strength', 'form', 'dose', 'route', 'frequency', 'duration_days', 'quantity', 'instructions', 'status', 'stop_reason']),
            'stopped_at' => $p->stopped_at?->toIso8601String(), 'dispensed_at' => $p->dispensed_at?->toIso8601String()];
    }

    public function fact(PatientFact $f): array
    {
        return [...$this->base($f), 'value' => $f->value];
    }

    public function visit(Visit $v): array
    {
        return [...$this->base($v), 'type' => $v->type, 'reason' => $v->reason, 'status' => $v->status, 'closed_at' => $v->closed_at?->toIso8601String()];
    }
}
