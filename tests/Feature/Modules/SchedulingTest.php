<?php

namespace Tests\Feature\Modules;

use App\Models\Facility;
use App\Models\Patient;
use App\Models\User;
use App\Modules\Appointments\Appointment;
use App\Modules\Patients\FacilityJoining;
use App\Modules\Scheduling\Scheduling;
use App\Services\PatientIdentity;
use Carbon\CarbonImmutable;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Auth\AuthTestCase;

/**
 * Bookable hours (spec 0001): General Hospital sets its weekly hours, closed dates and
 * notice; NHC lists the free slots, counts places, and warns staff who book against them.
 * The clock is Monday 7 Jan 2030, 08:00 in Lagos (07:00 UTC).
 */
class SchedulingTest extends AuthTestCase
{
    private Facility $g;

    private Patient $ada;

    private string $admin;

    private string $desk;

    private string $nurse;

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake(fn () => Http::response(['ok' => true]));
        $this->travelTo(CarbonImmutable::parse('2030-01-07 08:00', 'Africa/Lagos'));
        $this->g = $this->hospital('General Hospital');
        $this->ada = app(PatientIdentity::class)->registerAccountHolder('08062345678', ['first_name' => 'Ada', 'last_name' => 'Obi']);
        $this->admin = $this->staffToken($this->staff($this->g, 'admin', 'admin@g.example'));
        $this->desk = $this->staffToken($this->staff($this->g, 'front_desk', 'desk@g.example'));
        $this->nurse = $this->staffToken($this->staff($this->g, 'nurse', 'nurse@g.example'));
    }

    protected function tearDown(): void
    {
        foreach (['slot_holds', 'booking_hours', 'booking_closures', 'booking_settings', 'appointments', 'care_bookings', 'care_services'] as $t) {
            DB::table($t)->delete();
        }
        parent::tearDown();
    }

    private function url(string $path = ''): string
    {
        return "/api/staff/facilities/{$this->g->id}/schedule{$path}";
    }

    private function as(string $token): static
    {
        return $this->fresh()->withToken($token);
    }

    /** Monday to Friday, 9 to 12 and 2 to 5, half hour slots, two people each. */
    private function weekdays(?string $service = null, int $capacity = 2): void
    {
        $windows = [];
        foreach (range(1, 5) as $d) {
            $windows[] = ['weekday' => $d, 'opens_at' => '09:00', 'closes_at' => '12:00', 'slot_minutes' => 30, 'capacity' => $capacity];
            $windows[] = ['weekday' => $d, 'opens_at' => '14:00', 'closes_at' => '17:00', 'slot_minutes' => 30, 'capacity' => $capacity];
        }
        $this->as($this->admin)->putJson($this->url().($service ? "/windows?service_id=$service" : '/windows'), ['windows' => $windows])->assertOk();
    }

    private function visit(string $lagos, string $status = 'requested', bool $slot = true): Appointment
    {
        $at = CarbonImmutable::parse($lagos, 'Africa/Lagos')->utc();

        return Appointment::create(['facility_id' => $this->g->id, 'patient_id' => $this->ada->id, 'status' => $status, 'history' => [],
            'requested_by' => 'patient', ...($slot ? ['slot_at' => $at] : ['scheduled_at' => $at])]);
    }

    private function slots(?string $service = null, int $days = 7): array
    {
        return $this->as($this->desk)->getJson($this->url('/slots').'?days='.$days.($service ? "&service_id=$service" : ''))->assertOk()->json();
    }

    private function day(array $slots, string $date): array
    {
        return collect($slots['days'])->firstWhere('date', $date);
    }

    public function test_admin_sets_hours_and_patients_see_free_slots_from_the_notice_on(): void
    {
        $this->assertFalse($this->slots()['has_hours']);
        $this->weekdays();

        $s = $this->slots();
        $this->assertTrue($s['has_hours']);
        $mon = $this->day($s, '2030-01-07');
        // Two hours' notice from 08:00: the first slot is 10:00 Lagos, sent as UTC with Z.
        $this->assertSame(['at' => '2030-01-07T09:00:00Z', 'left' => 2], $mon['slots'][0]);
        $this->assertCount(4 + 6, $mon['slots']);
        $this->assertSame([true, false], [$mon['open'], $mon['full']]);
        $sat = $this->day($s, '2030-01-12');
        $this->assertSame([false, false, []], [$sat['open'], $sat['full'], $sat['slots']]);
        $this->assertCount(7, $s['days']);

        // Settings: no notice, and only a week ahead.
        $this->as($this->desk)->putJson($this->url('/settings'), ['horizon_days' => 7, 'min_notice_minutes' => 0])->assertOk();
        $this->assertSame('2030-01-07T08:00:00Z', $this->day($this->slots(), '2030-01-07')['slots'][0]['at']);
        $this->assertSame('2030-01-14', collect($this->slots(days: 30)['days'])->last()['date']);

        $show = $this->as($this->desk)->getJson($this->url())->assertOk();
        $this->assertCount(10, $show->json('windows'));
        $this->assertSame(['09:00', '12:00'], [$show->json('windows.0.opens_at'), $show->json('windows.0.closes_at')]);
    }

    public function test_only_admins_and_front_desk_set_hours_and_windows_must_make_sense(): void
    {
        $w = fn (array ...$ws) => ['windows' => $ws];
        $this->as($this->nurse)->getJson($this->url())->assertForbidden();
        $this->as($this->nurse)->putJson($this->url('/windows'), $w())->assertForbidden();

        $this->as($this->admin)->putJson($this->url('/windows'), $w(
            ['weekday' => 1, 'opens_at' => '09:00', 'closes_at' => '12:00', 'slot_minutes' => 30, 'capacity' => 1],
            ['weekday' => 1, 'opens_at' => '11:30', 'closes_at' => '13:00', 'slot_minutes' => 30, 'capacity' => 1],
        ))->assertUnprocessable()->assertJsonPath('message', 'Windows 1 and 2 overlap on Monday.');
        $this->as($this->admin)->putJson($this->url('/windows'), $w(['weekday' => 2, 'opens_at' => '09:00', 'closes_at' => '09:15', 'slot_minutes' => 30, 'capacity' => 1]))
            ->assertUnprocessable();
        $this->as($this->admin)->putJson($this->url('/windows'), $w(['weekday' => 2, 'opens_at' => '09:00', 'closes_at' => '10:00', 'slot_minutes' => 25, 'capacity' => 1]))
            ->assertUnprocessable();
        $this->as($this->admin)->putJson($this->url('/windows'), $w(['weekday' => 2, 'opens_at' => '18:00', 'closes_at' => '08:00', 'slot_minutes' => 30, 'capacity' => 1]))
            ->assertUnprocessable();
        // Back to back windows are fine, and a later save replaces the timetable.
        $this->as($this->admin)->putJson($this->url('/windows'), $w(
            ['weekday' => 1, 'opens_at' => '09:00', 'closes_at' => '12:00', 'slot_minutes' => 30, 'capacity' => 1],
            ['weekday' => 1, 'opens_at' => '12:00', 'closes_at' => '23:59', 'slot_minutes' => 60, 'capacity' => 1],
        ))->assertOk()->assertJsonCount(2, 'windows');
        $this->as($this->admin)->putJson($this->url('/windows'), $w())->assertOk()->assertJsonCount(0, 'windows');
        $this->assertFalse($this->slots()['has_hours']);
    }

    public function test_places_taken_count_visits_bookings_and_live_holds_only(): void
    {
        $this->weekdays();
        $at = '2030-01-08 10:00';
        $this->visit($at);
        $this->assertSame(1, $this->day($this->slots(), '2030-01-08')['slots'][2]['left']);

        // A confirmed visit at 10:15 sits in the 10:00 slot; declined and expired ones don't count.
        $this->visit('2030-01-08 10:15', 'confirmed', slot: false);
        $this->visit($at, 'declined');
        $this->visit($at, 'expired');
        $tue = $this->day($this->slots(), '2030-01-08');
        $this->assertSame('2030-01-08T08:30:00Z', $tue['slots'][1]['at']);
        $this->assertSame('2030-01-08T09:30:00Z', $tue['slots'][2]['at']);  // 10:00 Lagos (09:00Z) is full, 10:30 is next

        // A hold counts until it expires.
        DB::table('slot_holds')->insert(['id' => (string) str()->ulid(), 'facility_id' => $this->g->id, 'service_id' => (string) str()->ulid(),
            'slot_at' => CarbonImmutable::parse('2030-01-08 11:00', 'Africa/Lagos')->utc(), 'reference' => 'HCB-1', 'expires_at' => now()->addMinutes(30)]);
        $this->assertSame(1, collect($this->day($this->slots(), '2030-01-08')['slots'])->firstWhere('at', '2030-01-08T10:00:00Z')['left']);
        $this->travel(31)->minutes();
        $this->assertSame(2, collect($this->day($this->slots(), '2030-01-08')['slots'])->firstWhere('at', '2030-01-08T10:00:00Z')['left']);

        // A day with every slot full is marked full.
        foreach (['09:00', '09:30', '10:00', '10:30', '11:00', '11:30', '14:00', '14:30', '15:00', '15:30', '16:00', '16:30'] as $t) {
            $this->visit("2030-01-09 $t");
            $this->visit("2030-01-09 $t");
        }
        $wed = $this->day($this->slots(), '2030-01-09');
        $this->assertSame([true, true, []], [$wed['open'], $wed['full'], $wed['slots']]);
    }

    public function test_a_service_with_its_own_hours_has_its_own_timetable_and_capacity(): void
    {
        $this->weekdays();
        $cardio = (string) str()->ulid();
        DB::table('care_services')->insert(['id' => $cardio, 'facility_id' => $this->g->id, 'kind' => 'consultation', 'name' => 'Cardiology consultation', 'price_kobo' => 2000000]);
        // Before its own hours it uses the facility's.
        $this->assertTrue($this->slots($cardio)['has_hours']);
        $this->assertNull($this->slots($cardio)['timetable_service_id']);

        $this->as($this->admin)->putJson($this->url("/windows?service_id=$cardio"), ['windows' => [
            ['weekday' => 2, 'opens_at' => '10:00', 'closes_at' => '12:00', 'slot_minutes' => 60, 'capacity' => 1],
        ]])->assertOk();
        $s = $this->slots($cardio);
        $this->assertSame($cardio, $s['timetable_service_id']);
        $this->assertSame(['2030-01-08'], collect($s['days'])->where('open', true)->pluck('date')->all());
        $this->assertSame([['at' => '2030-01-08T09:00:00Z', 'left' => 1], ['at' => '2030-01-08T10:00:00Z', 'left' => 1]], $this->day($s, '2030-01-08')['slots']);

        // Visits fill the facility timetable, not the cardiologist's.
        $this->visit('2030-01-08 10:00');
        $this->visit('2030-01-08 10:00');
        $this->assertSame(1, $this->day($this->slots($cardio), '2030-01-08')['slots'][0]['left']);

        // Another facility's service is not found; an empty list removes the override.
        $this->as($this->admin)->getJson($this->url('?service_id='.str()->ulid()))->assertNotFound();
        $this->as($this->admin)->putJson($this->url("/windows?service_id=$cardio"), ['windows' => []])->assertOk();
        $this->assertNull($this->slots($cardio)['timetable_service_id']);
        $this->assertTrue($this->as($this->admin)->getJson($this->url("?service_id=$cardio"))->json('uses_facility_hours'));
    }

    public function test_closed_dates_hide_slots_and_report_bookings_on_them(): void
    {
        $this->weekdays();
        $this->visit('2030-01-09 10:00');
        $this->visit('2030-01-09 14:00', 'confirmed', slot: false);
        $this->visit('2030-01-10 14:00');

        $r = $this->as($this->desk)->postJson($this->url('/closures'), ['starts_on' => '2030-01-09', 'reason' => 'Staff training'])->assertCreated();
        $this->assertSame([2, '2030-01-09', '2030-01-09'], [$r->json('affected'), $r->json('closure.starts_on'), $r->json('closure.ends_on')]);
        $wed = $this->day($this->slots(), '2030-01-09');
        $this->assertSame([false, []], [$wed['open'], $wed['slots']]);

        $this->as($this->desk)->postJson($this->url('/closures'), ['starts_on' => '2030-01-01'])->assertUnprocessable();
        $this->as($this->desk)->postJson($this->url('/closures'), ['starts_on' => '2030-01-11', 'ends_on' => '2030-01-10'])->assertUnprocessable();

        $this->as($this->desk)->deleteJson($this->url('/closures/'.$r->json('closure.id')))->assertNoContent();
        $this->assertTrue($this->day($this->slots(), '2030-01-09')['open']);
        $this->as($this->desk)->deleteJson($this->url('/closures/'.$r->json('closure.id')))->assertNotFound();
    }

    public function test_the_last_place_is_refused_and_only_exact_slots_are_taken(): void
    {
        $this->weekdays(capacity: 1);
        $s = app(Scheduling::class);
        $at = CarbonImmutable::parse('2030-01-08 10:00', 'Africa/Lagos')->utc();
        $s->take($this->g, null, $at, fn () => $this->visit('2030-01-08 10:00'));

        foreach ([[$at, 'taken'], [$at->addMinutes(10), 'not_a_slot'], [CarbonImmutable::parse('2030-01-07 09:00', 'Africa/Lagos'), 'not_bookable'],
            [CarbonImmutable::parse('2030-01-12 10:00', 'Africa/Lagos'), 'not_a_slot'], [CarbonImmutable::parse('2030-03-05 10:00', 'Africa/Lagos'), 'not_bookable']] as [$when, $code]) {
            try {
                $s->take($this->g, null, $when, fn () => $this->fail('should not save'));
                $this->fail("$code was not refused");
            } catch (HttpResponseException $e) {
                $this->assertSame([409, $code], [$e->getResponse()->getStatusCode(), $e->getResponse()->getData(true)['code']]);
            }
        }
        $this->assertSame(1, Appointment::count());
    }

    public function test_staff_confirm_the_picked_slot_and_are_warned_outside_hours(): void
    {
        $this->weekdays(capacity: 1);
        $a = $this->visit('2030-01-08 10:00');
        $url = "/api/staff/facilities/{$this->g->id}/appointments/{$a->id}/confirm";

        // No time given: the slot the patient picked.
        $this->as($this->desk)->postJson($url)->assertOk()->assertJsonPath('scheduled_at', CarbonImmutable::parse('2030-01-08 10:00', 'Africa/Lagos')->utc()->toIso8601String());

        // Moving it to the evening is outside hours; a second visit at 10:00 would be over capacity.
        $evening = CarbonImmutable::parse('2030-01-08 19:00', 'Africa/Lagos')->toIso8601String();
        $this->as($this->desk)->postJson($url, ['scheduled_at' => $evening])->assertStatus(409)->assertJsonPath('reasons', ['outside_hours']);
        $this->as($this->desk)->postJson($url, ['scheduled_at' => $evening, 'force' => true])->assertOk();
        $this->assertSame('Booked outside hours', Appointment::find($a->id)->history[1]['flag']);

        $b = $this->visit('2030-01-08 10:30');
        $this->visit('2030-01-08 10:00', 'confirmed', slot: false);
        $this->as($this->desk)->postJson("/api/staff/facilities/{$this->g->id}/appointments/{$b->id}/confirm",
            ['scheduled_at' => CarbonImmutable::parse('2030-01-08 10:00', 'Africa/Lagos')->toIso8601String()])
            ->assertStatus(409)->assertJsonPath('reasons', ['full']);
    }

    public function test_boards_flag_bookings_that_no_longer_fit_the_hours(): void
    {
        $this->weekdays();
        $ok = $this->visit('2030-01-08 10:00');
        $closed = $this->visit('2030-01-09 10:00');
        $evening = $this->visit('2030-01-10 18:00');
        $this->as($this->desk)->postJson($this->url('/closures'), ['starts_on' => '2030-01-09'])->assertCreated();

        $rows = collect($this->as($this->desk)->getJson("/api/staff/facilities/{$this->g->id}/appointments?view=requests")->assertOk()->json('appointments'))
            ->keyBy('id');
        $this->assertSame([], $rows[$ok->id]['outside_hours']);
        $this->assertSame(['closed'], $rows[$closed->id]['outside_hours']);
        $this->assertSame(['outside_hours'], $rows[$evening->id]['outside_hours']);
    }

    public function test_today_is_lagos_today_not_the_servers(): void
    {
        $this->weekdays();
        $this->as($this->desk)->putJson($this->url('/settings'), ['horizon_days' => 30, 'min_notice_minutes' => 0])->assertOk();
        // Sunday 23:30 UTC is already Monday 00:30 in Lagos.
        $this->travelTo(CarbonImmutable::parse('2030-01-13 23:30', 'UTC'));
        $s = $this->slots();
        $this->assertSame('2030-01-14', $s['days'][0]['date']);
        $this->assertSame('2030-01-14T08:00:00Z', $s['days'][0]['slots'][0]['at']);
    }

    private function patientToken(): string
    {
        app(FacilityJoining::class)->link($this->ada, $this->g, User::first());

        return $this->ada->createToken('t', ['patient'])->plainTextToken;
    }

    public function test_a_patient_picks_a_free_slot_and_a_taken_one_is_refused(): void
    {
        $token = $this->patientToken();
        $other = $this->hospital('Not Joined Hospital');
        $ask = fn (array $body) => $this->as($token)->postJson('/api/patient/appointments', ['facility_id' => $this->g->id, ...$body]);

        // No hours yet: a day and a time of day, as before; slots say so.
        $this->as($token)->getJson("/api/patient/facilities/{$this->g->id}/slots")->assertOk()->assertJsonPath('has_hours', false);
        $ask(['slot_at' => '2030-01-08T09:00:00Z'])->assertUnprocessable();
        $ask(['preferred_date' => '2030-01-08', 'preferred_time' => 'morning'])->assertCreated()->assertJsonPath('slot_at', null);

        $this->weekdays(capacity: 1);
        $this->as($token)->getJson("/api/patient/facilities/{$other->id}/slots")->assertUnprocessable();
        $s = $this->as($token)->getJson("/api/patient/facilities/{$this->g->id}/slots?days=3")->assertOk();
        $this->assertSame([true, '2030-01-07T09:00:00Z'], [$s->json('has_hours'), $s->json('days.0.slots.0.at')]);

        $ask(['slot_at' => '2030-01-08T09:00:00Z', 'reason' => 'Eye check'])->assertCreated()
            ->assertJsonPath('status', 'requested')->assertJsonPath('slot_at', '2030-01-08T09:00:00Z')->assertJsonPath('preferred_date', '2030-01-08');
        $ask(['slot_at' => '2030-01-08T09:00:00Z'])->assertStatus(409)->assertJsonPath('message', 'That time was just taken. Pick another.');
        $ask(['slot_at' => '2030-01-08T09:10:00Z'])->assertStatus(409)->assertJsonPath('code', 'not_a_slot');
        $this->assertNotContains('2030-01-08T09:00:00Z', collect($this->as($token)->getJson("/api/patient/facilities/{$this->g->id}/slots?days=3")->json('days.1.slots'))->pluck('at'));

        // An older app still sends a day and a time of day: accepted, without holding a place.
        $ask(['preferred_date' => '2030-01-09', 'preferred_time' => 'any'])->assertCreated()->assertJsonPath('slot_at', null);
        $this->assertSame(3, Appointment::count());
    }
}
