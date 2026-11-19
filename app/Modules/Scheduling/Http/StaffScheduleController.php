<?php

namespace App\Modules\Scheduling\Http;

use App\Http\Controllers\Controller;
use App\Models\Facility;
use App\Modules\Scheduling\Scheduling;
use App\Modules\StaffAccess;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Admins and the front desk set when the facility takes bookings (`schedule.manage`). */
class StaffScheduleController extends Controller
{
    use StaffAccess;

    public function __construct(private Scheduling $scheduling) {}

    private function allow(Request $request, Facility $facility): void
    {
        $this->requirePermission($request, $facility, 'schedule.manage', "Your role doesn't set booking hours.");
    }

    private function mutate(Request $request, Facility $facility, Closure $change): mixed
    {
        return Scheduling::db()->transaction(function () use ($request, $facility, $change) {
            $this->scheduling->lock($facility);
            $this->allow($request, $facility->fresh());

            return $change();
        });
    }

    /** A care service at this facility, or null for the facility timetable. */
    private function service(Request $request, Facility $facility): ?string
    {
        $id = $request->validate(['service_id' => ['nullable', 'string', 'max:26']])['service_id'] ?? null;
        abort_if($id && ! Scheduling::db()->table('care_services')->where('facility_id', $facility->id)->where('id', $id)->exists(), 404, 'No such service here.');

        return $id;
    }

    public function show(Request $request, Facility $facility)
    {
        $this->allow($request, $facility);
        $service = $this->service($request, $facility);
        $own = $this->scheduling->windows($facility, $service);

        return [
            'timezone' => Scheduling::TZ,
            'today' => Scheduling::today()->toDateString(),
            'closure_until' => Scheduling::today()->addYear()->toDateString(),
            'service_id' => $service,
            'windows' => $own->map(fn ($w) => Scheduling::windowOut($w))->values(),
            // A service without its own windows uses the facility's.
            'uses_facility_hours' => $service !== null && $own->isEmpty(),
            'has_hours' => $this->scheduling->hasHours($facility, $this->scheduling->timetableFor($facility, $service)),
            'settings' => $this->scheduling->settings($facility),
            'closures' => $this->scheduling->closures($facility)->map(fn ($c) => Scheduling::closureOut($c))->values(),
            'services_with_hours' => Scheduling::db()->table('booking_hours')->where('facility_id', $facility->id)
                ->whereNotNull('service_id')->distinct()->pluck('service_id')->values(),
            'slot_minutes' => Scheduling::SLOT_MINUTES,
        ];
    }

    /** What patients see: the free slots, so staff can check their hours. */
    public function slots(Request $request, Facility $facility)
    {
        $this->allow($request, $facility);
        $d = $request->validate(['from' => ['nullable', 'date'], 'days' => ['nullable', 'integer', 'min:1', 'max:181']]);

        return $this->scheduling->slots($facility, $this->service($request, $facility), $d['from'] ?? null, $d['days'] ?? null);
    }

    public function windows(Request $request, Facility $facility)
    {
        $this->allow($request, $facility);
        $service = $this->service($request, $facility);
        $d = $request->validate([
            'windows' => ['present', 'array', 'max:70'],
            'windows.*.weekday' => ['required', 'integer', 'between:1,7'],
            'windows.*.opens_at' => ['required', 'date_format:H:i'],
            'windows.*.closes_at' => ['required', 'date_format:H:i', 'after:windows.*.opens_at'],
            'windows.*.slot_minutes' => ['required', 'integer', Rule::in(Scheduling::SLOT_MINUTES)],
            'windows.*.capacity' => ['required', 'integer', 'between:1,500'],
        ], ['windows.*.closes_at.after' => 'A window must close after it opens.']);

        return $this->mutate($request, $facility, fn () => ['windows' => $this->scheduling->saveWindows($facility, $service, $d['windows'])->map(fn ($w) => Scheduling::windowOut($w))->values()]);
    }

    public function settings(Request $request, Facility $facility)
    {
        $this->allow($request, $facility);
        $d = $request->validate([
            'horizon_days' => ['required', 'integer', 'between:1,180'],
            'min_notice_minutes' => ['required', 'integer', 'between:0,10080'],
        ]);

        return $this->mutate($request, $facility, fn () => $this->scheduling->saveSettings($facility, $d, $request->user()->name));
    }

    public function addClosure(Request $request, Facility $facility)
    {
        $this->allow($request, $facility);
        $today = Scheduling::today()->toDateString();
        $d = $request->validate([
            'starts_on' => ['required', 'date_format:Y-m-d', 'after_or_equal:'.$today, 'before_or_equal:'.Scheduling::today()->addYear()->toDateString()],
            'ends_on' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:starts_on', 'before_or_equal:'.Scheduling::today()->addYear()->toDateString()],
            'reason' => ['nullable', 'string', 'max:150'],
        ], ['starts_on.after_or_equal' => 'Pick a date from today on.']);

        return $this->mutate($request, $facility, function () use ($request, $facility, $d) {
            [$closure, $affected] = $this->scheduling->addClosure($facility, $d['starts_on'], $d['ends_on'] ?? $d['starts_on'], $d['reason'] ?? null, $request->user()->name);

            return response()->json(['closure' => Scheduling::closureOut($closure), 'affected' => $affected], 201);
        });
    }

    public function removeClosure(Request $request, Facility $facility, string $id)
    {
        $this->allow($request, $facility);

        return $this->mutate($request, $facility, function () use ($facility, $id) {
            $this->scheduling->removeClosure($facility, $id);

            return response()->noContent();
        });
    }
}
