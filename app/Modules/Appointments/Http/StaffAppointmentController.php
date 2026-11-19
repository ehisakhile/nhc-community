<?php

namespace App\Modules\Appointments\Http;

use App\Http\Controllers\Controller;
use App\Models\Facility;
use App\Models\Patient;
use App\Modules\Appointments\Appointment;
use App\Modules\Appointments\Visits;
use App\Modules\Patients\FacilityJoining;
use App\Modules\Scheduling\Scheduling;
use App\Modules\StaffAccess;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

/** The front desk's visit list: requests to answer, today's queue, what's coming. */
class StaffAppointmentController extends Controller
{
    use StaffAccess;

    public function __construct(private Visits $visits, private Scheduling $scheduling) {}

    private function mine(Facility $facility, string $id): Appointment
    {
        return Appointment::with(['facility', 'patient'])->where('facility_id', $facility->id)->findOrFail($id);
    }

    public function index(Request $request, Facility $facility)
    {
        $this->requirePermission($request, $facility, 'patients.view', "Your role can't see visits.");
        $view = $request->validate(['view' => ['nullable', Rule::in(['requests', 'today', 'upcoming', 'past'])]])['view'] ?? 'today';
        $q = Appointment::with(['facility', 'patient'])->where('facility_id', $facility->id);
        $today = [now('Africa/Lagos')->startOfDay()->utc(), now('Africa/Lagos')->endOfDay()->utc()];
        match ($view) {
            'requests' => $q->where('status', 'requested')->oldest(),
            'today' => $q->whereIn('status', ['confirmed', 'checked_in', 'completed', 'missed'])->whereBetween('scheduled_at', $today)->orderBy('scheduled_at'),
            'upcoming' => $q->where('status', 'confirmed')->where('scheduled_at', '>', $today[1])->orderBy('scheduled_at'),
            'past' => $q->where(fn ($w) => $w->whereIn('status', ['completed', 'missed', 'declined', 'cancelled', 'expired'])->orWhere('scheduled_at', '<', $today[0]))->latest('updated_at'),
        };

        $rows = $q->limit(200)->get();
        // Bookings still to come that no longer fit the hours (changed hours or a new closed date).
        $flags = $this->scheduling->flags($facility, $rows
            ->filter(fn ($a) => in_array($a->status, Scheduling::UPCOMING['appointments'], true) && ($a->scheduled_at ?? $a->slot_at)?->isFuture())
            ->map(fn ($a) => ['key' => $a->id, 'at' => CarbonImmutable::instance($a->scheduled_at ?? $a->slot_at), 'timetable' => null, 'ignore' => ['appointments', $a->id]])
            ->values()->all());

        return [
            'appointments' => $rows->map(fn ($a) => [...Visits::out($a, true), 'outside_hours' => $flags[$a->id] ?? []])->values(),
            'counts' => [
                'requests' => Appointment::where('facility_id', $facility->id)->where('status', 'requested')->count(),
                'today' => Appointment::where('facility_id', $facility->id)->whereIn('status', ['confirmed', 'checked_in'])->whereBetween('scheduled_at', $today)->count(),
            ],
        ];
    }

    public function store(Request $request, Facility $facility, FacilityJoining $joining)
    {
        $m = $this->requirePermission($request, $facility, 'appointments.manage', "Your role can't book visits.");
        $d = $request->validate([
            'nhc_id' => ['required', 'string', 'max:24'],
            'scheduled_at' => ['required', 'date', 'after:now'],
            'department' => ['nullable', 'string', 'max:100'],
            'reason' => ['nullable', 'string', 'max:1000'],
        ]);
        $patient = Patient::where('nhc_id', strtoupper(trim($d['nhc_id'])))->first();
        abort_unless($patient && $joining->ownLinks($patient)->contains('facility_id', $facility->id), 422, "That NHC ID isn't a patient here. Register them first.");

        return response()->json(Visits::out($this->visits->book($facility, $patient, Carbon::parse($d['scheduled_at']), $d['department'] ?? null,
            $d['reason'] ?? null, $request->user()->name)->load(['facility', 'patient']), true), 201);
    }

    public function confirm(Request $request, Facility $facility, string $id)
    {
        $this->requirePermission($request, $facility, 'appointments.manage', "Your role can't confirm visits.");
        $d = $request->validate(['scheduled_at' => ['nullable', 'date'], 'note' => ['nullable', 'string', 'max:500'], 'force' => ['sometimes', 'boolean']]);
        $at = isset($d['scheduled_at']) ? Carbon::parse($d['scheduled_at']) : null;

        return Visits::out($this->visits->confirm($this->mine($facility, $id), $at, $d['note'] ?? null, $request->user()->name, (bool) ($d['force'] ?? false)), true);
    }

    public function decline(Request $request, Facility $facility, string $id)
    {
        $this->requirePermission($request, $facility, 'appointments.manage', "Your role can't decline visits.");
        $note = $request->validate(['note' => ['required', 'string', 'min:3', 'max:500']])['note'];

        return Visits::out($this->visits->decline($this->mine($facility, $id), $note, $request->user()->name), true);
    }

    public function move(Request $request, Facility $facility, string $id, string $action)
    {
        $this->requirePermission($request, $facility, $action === 'checked_in' ? 'appointments.manage' : 'patients.view', "Your role can't do that.");

        return Visits::out($this->visits->move($this->mine($facility, $id), $action, $request->user()->name), true);
    }
}
