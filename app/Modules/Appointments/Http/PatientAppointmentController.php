<?php

namespace App\Modules\Appointments\Http;

use App\Http\Controllers\Controller;
use App\Models\Facility;
use App\Modules\Appointments\Appointment;
use App\Modules\Appointments\Visits;
use App\Modules\Scheduling\Scheduling;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** The patient's visits, from the Hospify Citizen app. */
class PatientAppointmentController extends Controller
{
    public function __construct(private Visits $visits) {}

    public function index(Request $request)
    {
        $patient = $request->user();

        return [
            'appointments' => Appointment::with('facility')->where('patient_id', $patient->id)->latest()->limit(100)->get()
                ->map(fn ($a) => Visits::out($a))->values(),
            'bookable_facility_ids' => $this->visits->bookable($patient),
        ];
    }

    public function store(Request $request)
    {
        $d = $request->validate([
            'facility_id' => ['required', 'string'],
            'department' => ['nullable', 'string', 'max:100'],
            'reason' => ['nullable', 'string', 'max:1000'],
            'slot_at' => ['nullable', 'date'],
            'preferred_date' => ['required_without:slot_at', 'nullable', 'date', 'after_or_equal:today', 'before:+91 days'],
            'preferred_time' => ['nullable', Rule::in(array_keys(Visits::TIMES))],
        ]);
        $a = $this->visits->request($request->user(), Facility::findOrFail($d['facility_id']), $d);

        return response()->json(Visits::out($a->load('facility')), 201);
    }

    /** Free times at a facility the patient can book at, by Lagos day. has_hours false: ask for a day and a time of day. */
    public function slots(Request $request, Scheduling $scheduling, string $facility)
    {
        $f = Facility::findOrFail($facility);
        abort_unless(in_array($f->id, $this->visits->bookable($request->user()), true), 422, "You can book at hospitals you've joined that take bookings on NHC.");
        $d = $request->validate(['from' => ['nullable', 'date'], 'days' => ['nullable', 'integer', 'min:1', 'max:181']]);

        return $scheduling->slots($f, null, $d['from'] ?? null, $d['days'] ?? null);
    }

    public function cancel(Request $request, string $id)
    {
        $a = Appointment::where('patient_id', $request->user()->id)->findOrFail($id);
        $reason = $request->validate(['reason' => ['nullable', 'string', 'max:500']])['reason'] ?? null;

        return Visits::out($this->visits->cancel($a, $reason)->load('facility'));
    }
}
