<?php

namespace App\Modules\Appointments;

use App\Models\Facility;
use App\Models\Patient;
use App\Modules\FeatureHub\Features;
use App\Modules\Patients\FacilityJoining;
use App\Modules\Scheduling\Scheduling;
use App\Support\TellPatient;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;

/**
 * Visits: a patient asks for one at a facility they've joined (Hospify app),
 * or the front desk books one. The facility confirms a time or declines,
 * checks the patient in, and marks it done or missed. The patient hears each step.
 *
 *   requested -> confirmed -> checked_in -> completed
 *   requested -> declined;  requested / confirmed -> cancelled (by the patient); confirmed -> missed
 */
class Visits
{
    public const TIMES = ['morning' => 'in the morning', 'afternoon' => 'in the afternoon', 'evening' => 'in the evening', 'any' => 'at any time'];

    public const MAX_OPEN_PER_FACILITY = 3;

    public function __construct(private Features $features, private FacilityJoining $joining, private Scheduling $scheduling) {}

    /** Facilities this patient can book at: ones they've joined, live, with appointments on. */
    public function bookable(Patient $patient): array
    {
        $ids = $this->joining->ownLinks($patient)->pluck('facility_id');

        return Facility::whereIn('id', $ids)->where('status', 'active')->get()
            ->filter(fn ($f) => $this->features->enabled($f, 'appointments'))->pluck('id')->all();
    }

    public function request(Patient $patient, Facility $facility, array $d): Appointment
    {
        abort_unless(in_array($facility->id, $this->bookable($patient), true), 422, "You can book at hospitals you've joined that take bookings on NHC.");
        $open = Appointment::where('patient_id', $patient->id)->where('facility_id', $facility->id)->whereIn('status', ['requested', 'confirmed'])->count();
        abort_if($open >= self::MAX_OPEN_PER_FACILITY, 422, "You already have {$open} visits waiting at {$facility->name}. Cancel one to ask for another.");

        $base = ['facility_id' => $facility->id, 'patient_id' => $patient->id, 'department' => $d['department'] ?? null,
            'reason' => $d['reason'] ?? null, 'requested_by' => 'patient', 'history' => []];

        // A facility with booking hours: the patient picked a free slot, which holds a place.
        if (! empty($d['slot_at'])) {
            abort_unless($this->scheduling->hasHours($facility, null), 422, "{$facility->name} doesn't take bookings by time yet. Pick a day instead.");
            $at = CarbonImmutable::parse($d['slot_at'])->utc();

            return $this->scheduling->take($facility, null, $at, function () use ($base, $at) {
                $a = new Appointment([...$base, 'slot_at' => $at, 'preferred_date' => $at->setTimezone(Scheduling::TZ)->toDateString()]);
                $a->record('requested', 'Patient');

                return $a;
            });
        }

        // No hours (or an older app): a day and a time of day, confirmed by the facility. It doesn't hold a place.
        $a = new Appointment([...$base, 'preferred_date' => $d['preferred_date'], 'preferred_time' => $d['preferred_time'] ?? 'any']);
        $a->record('requested', 'Patient');

        return $a;
    }

    public function book(Facility $facility, Patient $patient, Carbon $at, ?string $department, ?string $reason, string $by): Appointment
    {
        $a = new Appointment([
            'facility_id' => $facility->id, 'patient_id' => $patient->id, 'department' => $department, 'reason' => $reason,
            'scheduled_at' => $at, 'requested_by' => 'staff', 'history' => [],
        ]);
        $a->record('confirmed', $by);
        TellPatient::send($patient, 'Visit booked', "{$facility->name} booked you in for ".$this->when($at).'.', ['appointment_id' => $a->id]);

        return $a;
    }

    /**
     * Confirms a request or moves a confirmed visit. With no time given it confirms the slot the
     * patient picked. A time outside the facility's hours, on a closed date or in a full slot is
     * refused with the reasons (409) unless $force, which notes it in the history.
     */
    public function confirm(Appointment $a, ?Carbon $at, ?string $note, string $by, bool $force = false): Appointment
    {
        abort_unless(in_array($a->status, ['requested', 'confirmed'], true), 409, "This visit can't be confirmed now.");
        $at ??= $a->slot_at;
        abort_unless($at, 422, 'Pick a time for this visit.');
        abort_if($at->isPast(), 422, 'Pick a time that hasn\'t passed.');
        $flag = $this->scheduling->confirmCheck($a->facility, null, CarbonImmutable::instance($at), $force, ['appointments', $a->id]);
        $moved = $a->status === 'confirmed';
        $a->fill(['scheduled_at' => $at, 'facility_note' => $note ?: $a->facility_note, 'reminded' => false]);
        $a->record('confirmed', $by, $note, $flag);
        TellPatient::send($a->patient, $moved ? 'Visit moved' : 'Visit confirmed',
            "{$a->facility->name}: ".$this->when($at).($note ? ". {$note}" : '.'), ['appointment_id' => $a->id]);

        return $a;
    }

    public function decline(Appointment $a, string $note, string $by): Appointment
    {
        abort_unless($a->status === 'requested', 409, 'Only a request can be declined.');
        $a->facility_note = $note;
        $a->record('declined', $by, $note);
        TellPatient::send($a->patient, 'Visit request declined', "{$a->facility->name}: {$note}", ['appointment_id' => $a->id]);

        return $a;
    }

    public function move(Appointment $a, string $to, string $by): Appointment
    {
        $from = ['checked_in' => ['confirmed'], 'completed' => ['checked_in', 'confirmed'], 'missed' => ['confirmed']][$to] ?? abort(422);
        abort_unless(in_array($a->status, $from, true), 409, "This visit can't be marked {$to} now.");
        $a->record($to, $by);

        return $a;
    }

    public function cancel(Appointment $a, ?string $reason): Appointment
    {
        abort_unless(in_array($a->status, ['requested', 'confirmed'], true), 409, 'This visit can no longer be cancelled.');
        $a->record('cancelled', 'Patient', $reason);

        return $a;
    }

    /**
     * Requests whose slot started before the facility confirmed: expired, and the patient told.
     * Conditional on still being requested, so it never undoes a staff confirm. Requests without a
     * time (the day and time of day flow) never expire here.
     */
    public function expireUnconfirmed(): int
    {
        $n = 0;
        Appointment::with(['facility', 'patient'])->where('status', 'requested')->whereRaw('coalesce(scheduled_at, slot_at) is not null')
            ->whereRaw('coalesce(scheduled_at, slot_at) <= ?', [now()])->limit(500)->get()
            ->each(function (Appointment $a) use (&$n) {
                $done = Appointment::whereKey($a->id)->where('status', 'requested')->update(['status' => 'expired', 'updated_at' => now(),
                    'history' => json_encode([...($a->history ?? []), ['status' => 'expired', 'at' => now()->toIso8601String(), 'by' => null, 'note' => 'Not confirmed before the time']])]);
                if ($done) {
                    TellPatient::send($a->patient, 'Visit not confirmed', "{$a->facility->name} didn't confirm your visit in time. Ask for another time in NHC.", ['appointment_id' => $a->id]);
                    $n++;
                }
            });

        return $n;
    }

    /** Evening before: a reminder for tomorrow's visits. */
    public function remindTomorrow(): int
    {
        $n = 0;
        Appointment::with(['facility', 'patient'])->where('status', 'confirmed')->where('reminded', false)
            ->whereBetween('scheduled_at', [now(Scheduling::TZ)->addDay()->startOfDay()->utc(), now(Scheduling::TZ)->addDay()->endOfDay()->utc()])
            ->each(function (Appointment $a) use (&$n) {
                TellPatient::send($a->patient, 'Visit tomorrow', "{$a->facility->name}, ".$a->scheduled_at->timezone('Africa/Lagos')->format('g:ia').'. Show your NHC ID at the front desk.', ['appointment_id' => $a->id]);
                $a->update(['reminded' => true]);
                $n++;
            });

        return $n;
    }

    public function when(Carbon $at): string
    {
        return $at->copy()->timezone('Africa/Lagos')->format('D j M, g:ia');
    }

    public static function out(Appointment $a, bool $forStaff = false): array
    {
        return [
            'id' => $a->id, 'status' => $a->status, 'department' => $a->department, 'reason' => $a->reason,
            'preferred_date' => $a->preferred_date?->toDateString(), 'preferred_time' => $a->preferred_time,
            'slot_at' => $a->slot_at ? Scheduling::z($a->slot_at) : null,
            'scheduled_at' => $a->scheduled_at?->toIso8601String(), 'facility_note' => $a->facility_note, 'requested_by' => $a->requested_by,
            'facility' => ['id' => $a->facility->id, 'name' => $a->facility->name, 'address' => $a->facility->address],
            'history' => $a->history, 'created_at' => $a->created_at?->toIso8601String(),
            ...($forStaff ? ['patient' => ['nhc_id' => $a->patient->nhc_id, 'name' => trim($a->patient->first_name.' '.$a->patient->last_name),
                'phone' => $a->patient->phone, 'date_of_birth' => $a->patient->date_of_birth?->toDateString()]] : []),
        ];
    }
}
