<?php

namespace App\Modules\Scheduling;

use App\Models\Facility;
use Carbon\CarbonImmutable;
use Closure;
use DateTimeInterface;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Bookable hours and free slots (spec 0001). A facility sets weekly windows
 * (slot length, people per slot), closed dates, how far ahead and with how much
 * notice. A care service may have its own windows; everything else shares the
 * facility timetable. Visits and care bookings both ask this class whether a
 * time is free, and take a place under the facility lock.
 *
 * Weekday and "today" are always Lagos (fixed +01:00, no daylight saving);
 * stored times are UTC.
 */
class Scheduling
{
    public const TZ = 'Africa/Lagos';

    public const SLOT_MINUTES = [15, 20, 30, 45, 60];

    public const HOLD_MINUTES = 30;

    public const DEFAULTS = ['horizon_days' => 30, 'min_notice_minutes' => 120];

    /** Statuses that hold a place in a slot. Nothing else ever counts. */
    public const COUNTED = [
        'appointments' => ['requested', 'confirmed'],
        'care_bookings' => ['paid', 'accepted', 'ready'],
    ];

    /** Bookings still to happen, for "outside hours now" and closed date counts. */
    public const UPCOMING = [
        'appointments' => ['requested', 'confirmed'],
        'care_bookings' => ['paid', 'accepted', 'ready'],
    ];

    public const REASONS = [
        'outside_hours' => 'outside your booking hours',
        'closed' => 'on a day you are closed',
        'full' => 'in a slot that is already full',
    ];

    public static function db(): ConnectionInterface
    {
        return DB::connection(config('tenancy.database.central_connection'));
    }

    // --- timetables ------------------------------------------------------------------------

    /** The timetable a booking of this service counts against: the service if it has its own windows, else the facility (null). */
    public function timetableFor(Facility $facility, ?string $serviceId): ?string
    {
        if ($serviceId && self::db()->table('booking_hours')->where(['facility_id' => $facility->id, 'service_id' => $serviceId])->exists()) {
            return $serviceId;
        }

        return null;
    }

    public function hasHours(Facility $facility, ?string $timetable): bool
    {
        return $this->windowsQuery($facility, $timetable)->exists();
    }

    private function windowsQuery(Facility $facility, ?string $timetable)
    {
        return self::db()->table('booking_hours')->where('facility_id', $facility->id)
            ->when($timetable, fn ($q) => $q->where('service_id', $timetable), fn ($q) => $q->whereNull('service_id'));
    }

    /** @return Collection<int, object> one timetable's windows, by weekday then time */
    public function windows(Facility $facility, ?string $timetable): Collection
    {
        return $this->windowsQuery($facility, $timetable)->orderBy('weekday')->orderBy('opens_at')->get()
            ->map(fn ($w) => (object) [...(array) $w, 'opens_at' => substr($w->opens_at, 0, 5), 'closes_at' => substr($w->closes_at, 0, 5),
                'weekday' => (int) $w->weekday, 'slot_minutes' => (int) $w->slot_minutes, 'capacity' => (int) $w->capacity]);
    }

    /** @return array{horizon_days: int, min_notice_minutes: int} */
    public function settings(Facility $facility): array
    {
        $row = self::db()->table('booking_settings')->where('facility_id', $facility->id)->first();

        return $row ? ['horizon_days' => (int) $row->horizon_days, 'min_notice_minutes' => (int) $row->min_notice_minutes] : self::DEFAULTS;
    }

    /** @return Collection<int, object> closed dates that end today or later */
    public function closures(Facility $facility, bool $upcomingOnly = true): Collection
    {
        return self::db()->table('booking_closures')->where('facility_id', $facility->id)
            ->when($upcomingOnly, fn ($q) => $q->where('ends_on', '>=', self::today()->toDateString()))
            ->orderBy('starts_on')->get();
    }

    // --- staff edits -----------------------------------------------------------------------

    /**
     * Replaces one timetable's windows. An empty list removes a service's own hours
     * (it falls back to the facility's). Windows on one weekday can't overlap.
     *
     * @param  list<array{weekday: int, opens_at: string, closes_at: string, slot_minutes: int, capacity: int}>  $windows
     */
    public function saveWindows(Facility $facility, ?string $serviceId, array $windows): Collection
    {
        $rows = [];
        foreach ($windows as $i => $w) {
            $opens = self::minutes($w['opens_at']);
            $closes = self::minutes($w['closes_at']);
            $n = $i + 1;
            $this->refuse($closes > 23 * 60 + 59, "Window {$n} must close by 23:59.", 422);
            $this->refuse($closes - $opens < (int) $w['slot_minutes'], "Window {$n} is shorter than one {$w['slot_minutes']} minute slot.", 422);
            foreach ($rows as $j => $r) {
                if ($r['weekday'] === (int) $w['weekday'] && $opens < self::minutes($r['closes_at']) && self::minutes($r['opens_at']) < $closes) {
                    $this->refuse(true, 'Windows '.($j + 1)." and {$n} overlap on ".self::dayName((int) $w['weekday']).'.', 422);
                }
            }
            $rows[] = ['weekday' => (int) $w['weekday'], 'opens_at' => self::hhmm($opens), 'closes_at' => self::hhmm($closes),
                'slot_minutes' => (int) $w['slot_minutes'], 'capacity' => (int) $w['capacity']];
        }

        self::db()->transaction(function () use ($facility, $serviceId, $rows) {
            $this->windowsQuery($facility, $serviceId)->delete();
            $now = now();
            self::db()->table('booking_hours')->insert(array_map(fn ($r) => [
                'id' => (string) str()->ulid(), 'facility_id' => $facility->id, 'service_id' => $serviceId, ...$r,
                'opens_at' => $r['opens_at'].':00', 'closes_at' => $r['closes_at'].':00', 'created_at' => $now, 'updated_at' => $now,
            ], $rows));
        });

        return $this->windows($facility, $serviceId);
    }

    public function saveSettings(Facility $facility, array $data, string $by): array
    {
        self::db()->table('booking_settings')->updateOrInsert(['facility_id' => $facility->id], [
            'horizon_days' => (int) $data['horizon_days'], 'min_notice_minutes' => (int) $data['min_notice_minutes'],
            'updated_by' => $by, 'updated_at' => now(), 'created_at' => now(),
        ]);

        return $this->settings($facility);
    }

    /** @return array{0: object, 1: int} the closed dates, and how many upcoming bookings fall on them */
    public function addClosure(Facility $facility, string $startsOn, string $endsOn, ?string $reason, string $by): array
    {
        $existing = self::db()->table('booking_closures')->where([
            'facility_id' => $facility->id, 'starts_on' => $startsOn, 'ends_on' => $endsOn, 'reason' => $reason,
        ])->first();
        $id = $existing?->id ?? (string) str()->ulid();
        if (! $existing) {
            self::db()->table('booking_closures')->insert(['id' => $id, 'facility_id' => $facility->id, 'starts_on' => $startsOn, 'ends_on' => $endsOn,
                'reason' => $reason, 'created_by' => $by, 'created_at' => now(), 'updated_at' => now()]);
        }

        $from = CarbonImmutable::parse($startsOn, self::TZ)->startOfDay()->utc();
        $to = CarbonImmutable::parse($endsOn, self::TZ)->endOfDay()->utc();
        $affected = 0;
        foreach (self::UPCOMING as $table => $statuses) {
            $affected += self::db()->table($table)->where('facility_id', $facility->id)->whereIn('status', $statuses)
                ->whereRaw('coalesce(scheduled_at, slot_at) between ? and ?', [$from, $to])->count();
        }

        return [self::db()->table('booking_closures')->find($id), $affected];
    }

    public function removeClosure(Facility $facility, string $id): void
    {
        $n = self::db()->table('booking_closures')->where(['facility_id' => $facility->id, 'id' => $id])->delete();
        $this->refuse($n === 0, 'No such closed date.', 404);
    }

    // --- free slots ------------------------------------------------------------------------

    /**
     * Free slots for one timetable, by Lagos day. Never a past slot, one inside
     * the minimum notice, one past the horizon, one on a closed date, or a full one.
     *
     * @return array{has_hours: bool, timetable_service_id: ?string, days: list<array{date: string, open: bool, full: bool, slots: list<array{at: string, left: int}>}>}
     */
    public function slots(Facility $facility, ?string $serviceId = null, ?string $from = null, ?int $days = null): array
    {
        $timetable = $this->timetableFor($facility, $serviceId);
        $windows = $this->windows($facility, $timetable);
        if ($windows->isEmpty()) {
            return ['has_hours' => false, 'timetable_service_id' => $timetable, 'days' => []];
        }
        $s = $this->settings($facility);
        $today = self::today();
        $last = $today->addDays($s['horizon_days']);
        $first = $from ? max(CarbonImmutable::parse($from, self::TZ)->startOfDay(), $today) : $today;
        $end = min($first->addDays(max(1, $days ?? 14) - 1), $last);
        $earliest = now()->addMinutes($s['min_notice_minutes']);

        $grid = $this->grid($windows, $this->closures($facility), $first, $end);
        $taken = $this->taken($facility, $timetable, $first->utc(), $end->endOfDay()->utc());

        $out = [];
        foreach ($grid as $date => $day) {
            $slots = [];
            $candidates = 0;
            foreach ($day['slots'] as [$start, $minutes, $capacity]) {
                if ($start->lt($earliest)) {
                    continue;
                }
                $candidates++;
                $left = $capacity - self::countIn($taken, $start, $minutes);
                if ($left > 0) {
                    $slots[] = ['at' => self::z($start), 'left' => $left];
                }
            }
            $open = $day['open'] && $candidates > 0;
            $out[] = ['date' => $date, 'open' => $open, 'full' => $open && $slots === [], 'slots' => $slots];
        }

        return ['has_hours' => true, 'timetable_service_id' => $timetable, 'days' => $out];
    }

    /**
     * Patient path: is this exactly a free slot on the timetable, inside notice and horizon?
     * Throws 409 "That time was just taken" when full, 422 when it isn't a slot at all.
     */
    public function assertFree(Facility $facility, ?string $timetable, CarbonImmutable $at, ?array $ignore = null): void
    {
        $s = $this->settings($facility);
        $lagos = $at->setTimezone(self::TZ);
        $this->refuse($at->lt(now()->addMinutes($s['min_notice_minutes'])) || $lagos->startOfDay()->gt(self::today()->addDays($s['horizon_days'])),
            'That time is no longer bookable. Pick another.', 409, 'not_bookable');
        $slot = $this->slotAt($facility, $timetable, $at);
        $this->refuse($slot === null, "That isn't one of the facility's times. Pick another.", 409, 'not_a_slot');
        [$minutes, $capacity] = $slot;
        $taken = $this->taken($facility, $timetable, $at, $at->addMinutes($minutes), $ignore);
        $this->refuse(self::countIn($taken, $at, $minutes) >= $capacity, 'That time was just taken. Pick another.', 409, 'taken');
    }

    /**
     * Takes a place: locks the facility row, checks the slot is still free, then runs $save
     * inside the same transaction. Every patient path that takes a place goes through here.
     */
    public function take(Facility $facility, ?string $timetable, CarbonImmutable $at, Closure $save, ?array $ignore = null): mixed
    {
        return self::db()->transaction(function () use ($facility, $timetable, $at, $save, $ignore) {
            $this->lock($facility);
            $this->assertFree($facility, $timetable, $at, $ignore);

            return $save();
        });
    }

    /**
     * A paid booking arriving after its hold: only capacity counts now (hours, closed dates
     * and notice were checked when the hold was placed). A time no window covers any more has room.
     */
    public function hasRoom(Facility $facility, ?string $timetable, CarbonImmutable $at): bool
    {
        $lagos = $at->setTimezone(self::TZ);
        $minute = $lagos->hour * 60 + $lagos->minute;
        foreach ($this->windows($facility, $timetable)->where('weekday', $lagos->dayOfWeekIso) as $w) {
            $opens = self::minutes($w->opens_at);
            if ($minute >= $opens && $minute < self::minutes($w->closes_at)) {
                $start = $at->subMinutes(($minute - $opens) % $w->slot_minutes)->setSecond(0);

                return self::countIn($this->taken($facility, $timetable, $start, $start->addMinutes($w->slot_minutes)), $start, $w->slot_minutes) < $w->capacity;
            }
        }

        return true;
    }

    /** Saves (or moves) the hold for one Hospify reference, taking a place under the facility lock. */
    public function hold(Facility $facility, string $serviceId, ?string $timetable, CarbonImmutable $at, string $reference): CarbonImmutable
    {
        $expires = now()->toImmutable()->addMinutes(self::HOLD_MINUTES);
        $this->take($facility, $timetable, $at, function () use ($facility, $serviceId, $timetable, $at, $reference, $expires) {
            $row = ['facility_id' => $facility->id, 'service_id' => $serviceId, 'timetable_service_id' => $timetable, 'slot_at' => $at, 'expires_at' => $expires];
            $q = self::db()->table('slot_holds')->where('reference', $reference);
            $q->exists() ? $q->update($row) : self::db()->table('slot_holds')->insert([...$row, 'id' => (string) str()->ulid(), 'reference' => $reference, 'created_at' => now()]);
        }, ['slot_holds', $reference]);

        return $expires;
    }

    public function releaseHold(string $reference): void
    {
        self::db()->table('slot_holds')->where('reference', $reference)->delete();
    }

    /**
     * Holds that ran out a day ago. An expired hold stops counting at once (see takenAll), but is
     * kept a day so a late payment still finds it and is booked on capacity alone.
     */
    public function dropExpiredHolds(): int
    {
        return self::db()->table('slot_holds')->where('expires_at', '<=', now()->subDay())->delete();
    }

    /** The facility lock: a row lock on the tenant, held until the surrounding transaction ends. */
    public function lock(Facility $facility): void
    {
        self::db()->table('tenants')->where('id', $facility->id)->lockForUpdate()->first();
    }

    // --- staff checks and flags ------------------------------------------------------------

    /**
     * Why a staff chosen time doesn't fit the timetable: outside_hours, closed, full.
     * Empty when it fits, or when the timetable has no hours (closed dates still count).
     *
     * @param  array{0: string, 1: string}|null  $ignore  [table, id] of the booking being moved, so it doesn't count against itself
     * @return list<string>
     */
    public function check(Facility $facility, ?string $timetable, CarbonImmutable $at, ?array $ignore = null): array
    {
        return $this->flags($facility, [['key' => 'one', 'at' => $at, 'timetable' => $timetable, 'ignore' => $ignore]])['one'] ?? [];
    }

    /** Refuses a staff time that doesn't fit, unless forced. Returns the history note when forced. */
    public function confirmCheck(Facility $facility, ?string $timetable, CarbonImmutable $at, bool $force, ?array $ignore = null): ?string
    {
        $reasons = $this->check($facility, $timetable, $at, $ignore);
        if ($reasons === []) {
            return null;
        }
        if (! $force) {
            throw new HttpResponseException(response()->json([
                'message' => 'That time is '.implode(', and ', array_map(fn ($r) => self::REASONS[$r], $reasons)).'. Confirm again to book it anyway.',
                'code' => 'outside_schedule', 'reasons' => $reasons,
            ], 409));
        }

        return implode('; ', array_map(fn ($r) => $r === 'full' ? 'Over capacity' : ($r === 'closed' ? 'Booked on a closed date' : 'Booked outside hours'), $reasons));
    }

    /**
     * Batch "outside hours now" check for a board: one query each for windows, closed
     * dates and places taken, however many rows.
     *
     * @param  list<array{key: string, at: ?CarbonImmutable, timetable: ?string, ignore?: ?array}>  $rows
     * @return array<string, list<string>> reasons by key, only for rows that don't fit
     */
    public function flags(Facility $facility, array $rows): array
    {
        $rows = array_values(array_filter($rows, fn ($r) => $r['at'] !== null));
        if ($rows === []) {
            return [];
        }
        $windows = self::db()->table('booking_hours')->where('facility_id', $facility->id)->get()
            ->groupBy(fn ($w) => $w->service_id ?? '');
        $closures = $this->closures($facility, false);
        $times = array_map(fn ($r) => $r['at'], $rows);
        $from = min($times)->subHours(2);
        $to = max($times)->addHours(2);
        $taken = $this->takenAll($facility, $from, $to);

        $out = [];
        foreach ($rows as $r) {
            $reasons = [];
            $lagos = $r['at']->setTimezone(self::TZ);
            if ($this->isClosed($closures, $lagos->toDateString())) {
                $reasons[] = 'closed';
            }
            $own = $windows->get($r['timetable'] ?? '', collect());
            if ($own->isNotEmpty()) {
                $minute = $lagos->hour * 60 + $lagos->minute;
                $window = $own->first(fn ($w) => (int) $w->weekday === $lagos->dayOfWeekIso
                    && $minute >= self::minutes($w->opens_at) && $minute < self::minutes($w->closes_at));
                if (! $window) {
                    $reasons[] = 'outside_hours';
                } else {
                    $offset = ($minute - self::minutes($window->opens_at)) % (int) $window->slot_minutes;
                    $start = $lagos->setTime(intdiv($minute - $offset, 60), ($minute - $offset) % 60)->utc();
                    $mine = $taken->filter(fn ($t) => $t['timetable'] === ($r['timetable'] ?? null)
                        && ! (isset($r['ignore']) && $r['ignore'] === [$t['table'], $t['id']]))->pluck('at');
                    if (self::countIn($mine, $start, (int) $window->slot_minutes) >= (int) $window->capacity) {
                        $reasons[] = 'full';
                    }
                }
            }
            if ($reasons) {
                $out[$r['key']] = $reasons;
            }
        }

        return $out;
    }

    // --- internals -------------------------------------------------------------------------

    /** Slot starts for each Lagos day in [first, end]: [date => [open, slots: [[start UTC, minutes, capacity]]]]. */
    private function grid(Collection $windows, Collection $closures, CarbonImmutable $first, CarbonImmutable $end): array
    {
        $byDay = $windows->groupBy('weekday');
        $grid = [];
        for ($d = $first; $d->lte($end); $d = $d->addDay()) {
            $date = $d->toDateString();
            $ws = $byDay->get($d->dayOfWeekIso, collect());
            $open = $ws->isNotEmpty() && ! $this->isClosed($closures, $date);
            $slots = [];
            if ($open) {
                foreach ($ws as $w) {
                    $close = self::minutes($w->closes_at);
                    for ($m = self::minutes($w->opens_at); $m + $w->slot_minutes <= $close; $m += $w->slot_minutes) {
                        $slots[] = [$d->setTime(intdiv($m, 60), $m % 60)->utc(), $w->slot_minutes, $w->capacity];
                    }
                }
                usort($slots, fn ($a, $b) => $a[0] <=> $b[0]);
            }
            $grid[$date] = ['open' => $open, 'slots' => $slots];
        }

        return $grid;
    }

    /** [minutes, capacity] when $at is exactly a slot start on the timetable and not on a closed date. */
    private function slotAt(Facility $facility, ?string $timetable, CarbonImmutable $at): ?array
    {
        $lagos = $at->setTimezone(self::TZ);
        if ($lagos->second !== 0 || $this->isClosed($this->closures($facility), $lagos->toDateString())) {
            return null;
        }
        $minute = $lagos->hour * 60 + $lagos->minute;
        foreach ($this->windows($facility, $timetable)->where('weekday', $lagos->dayOfWeekIso) as $w) {
            $opens = self::minutes($w->opens_at);
            if ($minute >= $opens && $minute + $w->slot_minutes <= self::minutes($w->closes_at) && ($minute - $opens) % $w->slot_minutes === 0) {
                return [$w->slot_minutes, $w->capacity];
            }
        }

        return null;
    }

    private function isClosed(Collection $closures, string $date): bool
    {
        return $closures->contains(fn ($c) => substr($c->starts_on, 0, 10) <= $date && $date <= substr($c->ends_on, 0, 10));
    }

    /**
     * When each place on one timetable is taken, between two UTC times: visits (always the facility
     * timetable), care bookings and unexpired holds on that timetable, in a counted status.
     *
     * @return Collection<int, CarbonImmutable>
     */
    private function taken(Facility $facility, ?string $timetable, CarbonImmutable $from, CarbonImmutable $to, ?array $ignore = null): Collection
    {
        return $this->takenAll($facility, $from, $to)
            ->filter(fn ($t) => $t['timetable'] === $timetable && ! ($ignore && $ignore === [$t['table'], $t['id']]))
            ->pluck('at')->values();
    }

    /** @return Collection<int, array{table: string, id: string, timetable: ?string, at: CarbonImmutable}> */
    private function takenAll(Facility $facility, CarbonImmutable $from, CarbonImmutable $to): Collection
    {
        $db = self::db();
        $visits = $db->table('appointments')->where('facility_id', $facility->id)->whereIn('status', self::COUNTED['appointments'])
            ->whereRaw('coalesce(scheduled_at, slot_at) between ? and ?', [$from, $to])
            ->selectRaw("'appointments' as tbl, id, null as timetable, coalesce(scheduled_at, slot_at) as at")->get();
        $care = $db->table('care_bookings')->where('facility_id', $facility->id)->whereIn('status', self::COUNTED['care_bookings'])
            ->whereRaw('coalesce(scheduled_at, slot_at) between ? and ?', [$from, $to])
            ->selectRaw("'care_bookings' as tbl, id, timetable_service_id as timetable, coalesce(scheduled_at, slot_at) as at")->get();
        $holds = $db->table('slot_holds')->where('facility_id', $facility->id)->where('expires_at', '>', now())
            ->whereBetween('slot_at', [$from, $to])
            ->selectRaw("'slot_holds' as tbl, reference as id, timetable_service_id as timetable, slot_at as at")->get();

        return $visits->concat($care)->concat($holds)->map(fn ($r) => [
            'table' => $r->tbl, 'id' => $r->id, 'timetable' => $r->timetable, 'at' => CarbonImmutable::parse($r->at, 'UTC'),
        ]);
    }

    private static function countIn(Collection $times, DateTimeInterface $start, int $minutes): int
    {
        $s = $start->getTimestamp();
        $e = $s + $minutes * 60;

        return $times->filter(fn ($t) => ($ts = $t->getTimestamp()) >= $s && $ts < $e)->count();
    }

    private function refuse(bool $when, string $message, int $status, ?string $code = null): void
    {
        if ($when) {
            throw new HttpResponseException(response()->json(array_filter(['message' => $message, 'code' => $code]), $status));
        }
    }

    public static function today(): CarbonImmutable
    {
        return CarbonImmutable::now(self::TZ)->startOfDay();
    }

    public static function z(DateTimeInterface $t): string
    {
        return CarbonImmutable::instance($t)->utc()->format('Y-m-d\TH:i:s\Z');
    }

    public static function minutes(string $hhmm): int
    {
        [$h, $m] = array_map('intval', explode(':', $hhmm));

        return $h * 60 + $m;
    }

    private static function hhmm(int $minutes): string
    {
        return sprintf('%02d:%02d', intdiv($minutes, 60), $minutes % 60);
    }

    public static function dayName(int $iso): string
    {
        return ['', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'][$iso];
    }

    public static function windowOut(object $w): array
    {
        return ['id' => $w->id, 'weekday' => $w->weekday, 'opens_at' => $w->opens_at, 'closes_at' => $w->closes_at,
            'slot_minutes' => $w->slot_minutes, 'capacity' => $w->capacity];
    }

    public static function closureOut(object $c): array
    {
        return ['id' => $c->id, 'starts_on' => substr($c->starts_on, 0, 10), 'ends_on' => substr($c->ends_on, 0, 10), 'reason' => $c->reason];
    }
}
