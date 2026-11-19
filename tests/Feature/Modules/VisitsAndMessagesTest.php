<?php

namespace Tests\Feature\Modules;

use App\Models\Facility;
use App\Models\Patient;
use App\Modules\Patients\FacilityJoining;
use App\Services\PatientIdentity;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Auth\AuthTestCase;

/** Ada asks for a visit and messages her hospital from the Hospify app; the front desk answers in the portal. */
class VisitsAndMessagesTest extends AuthTestCase
{
    private Facility $g;

    private Facility $other;

    private Patient $ada;

    private string $patient;

    private string $desk;

    private string $doctor;

    private array $pushes = [];

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.hospify.api_url' => 'https://hospify.test', 'services.hospify.events_secret' => 'shh']);
        Http::fake(function (Request $r) {
            if (str_contains($r->url(), '/nhc/patient-events')) {
                $this->pushes[] = json_decode($r->body(), true) + ['signed' => hash_equals(hash_hmac('sha256', $r->body(), 'shh'), $r->header('X-NHC-Signature')[0] ?? '')];
            }

            return Http::response(['ok' => true]);
        });
        $this->g = $this->hospital('General Hospital');
        $this->other = $this->hospital('Not Joined Hospital');
        $this->ada = app(PatientIdentity::class)->registerAccountHolder('08062345678', ['first_name' => 'Ada', 'last_name' => 'Obi']);
        DB::table('patients')->where('id', $this->ada->id)->update(['hospify_ref' => 'hfr_ada']);
        $deskUser = $this->staff($this->g, 'front_desk', 'desk@g.example');
        app(FacilityJoining::class)->link($this->ada, $this->g, $deskUser);
        $this->patient = $this->ada->createToken('t', ['patient'])->plainTextToken;
        $this->desk = $this->staffToken($deskUser);
        $this->doctor = $this->staffToken($this->staff($this->g, 'doctor', 'doc@g.example'));
    }

    protected function tearDown(): void
    {
        DB::table('messages')->delete();
        DB::table('message_threads')->delete();
        DB::table('appointments')->delete();
        parent::tearDown();
    }

    private function asPatient(): static
    {
        return $this->fresh()->withToken($this->patient);
    }

    private function asDesk(): static
    {
        return $this->fresh()->withToken($this->desk);
    }

    public function test_a_visit_is_asked_for_confirmed_and_checked_in(): void
    {
        $list = $this->asPatient()->getJson('/api/patient/appointments')->assertOk();
        $this->assertSame([$this->g->id], $list->json('bookable_facility_ids'));

        $id = $this->asPatient()->postJson('/api/patient/appointments', [
            'facility_id' => $this->g->id, 'department' => 'Eye clinic', 'reason' => 'Blurry vision', 'preferred_date' => now()->addDays(2)->toDateString(), 'preferred_time' => 'morning',
        ])->assertCreated()->assertJsonPath('status', 'requested')->json('id');
        $this->assertNotSame('Blurry vision', DB::table('appointments')->value('reason'));  // stored encrypted

        $requests = $this->asDesk()->getJson("/api/staff/facilities/{$this->g->id}/appointments?view=requests")->assertOk();
        $this->assertSame(['Ada Obi', 1], [$requests->json('appointments.0.patient.name'), $requests->json('counts.requests')]);

        $at = now('Africa/Lagos')->addDays(2)->setTime(10, 30);
        $this->asDesk()->postJson("/api/staff/facilities/{$this->g->id}/appointments/$id/confirm", ['scheduled_at' => $at->toIso8601String(), 'note' => 'Bring your glasses.'])
            ->assertOk()->assertJsonPath('status', 'confirmed');
        $this->assertSame(['Visit confirmed', 'hfr_ada', true], [$this->pushes[0]['title'], $this->pushes[0]['hospify_ref'], $this->pushes[0]['signed']]);
        $this->assertStringContainsString('10:30am. Bring your glasses.', $this->pushes[0]['body']);

        $this->asDesk()->postJson("/api/staff/facilities/{$this->g->id}/appointments/$id/checked_in")->assertOk()->assertJsonPath('status', 'checked_in');
        $this->fresh()->withToken($this->doctor)->postJson("/api/staff/facilities/{$this->g->id}/appointments/$id/completed")->assertOk();
        $this->asPatient()->postJson("/api/patient/appointments/$id/cancel")->assertStatus(409);
    }

    public function test_only_joined_facilities_and_a_few_at_a_time(): void
    {
        $ask = fn (Facility $f) => $this->asPatient()->postJson('/api/patient/appointments', ['facility_id' => $f->id, 'preferred_date' => now()->addDay()->toDateString()]);
        $ask($this->other)->assertUnprocessable();
        foreach (range(1, 3) as $_) {
            $ask($this->g)->assertCreated();
        }
        $ask($this->g)->assertUnprocessable();
    }

    public function test_declining_tells_the_patient_why_and_reminders_go_the_evening_before(): void
    {
        $this->travelTo(CarbonImmutable::parse('2030-01-07 18:00', 'Africa/Lagos'));  // the reminder runs at 6pm Lagos
        $id = $this->asPatient()->postJson('/api/patient/appointments', ['facility_id' => $this->g->id, 'preferred_date' => now()->addDay()->toDateString()])->json('id');
        $this->asDesk()->postJson("/api/staff/facilities/{$this->g->id}/appointments/$id/decline", ['note' => 'The eye clinic is closed that week.'])->assertOk();
        $this->assertSame('Visit request declined', end($this->pushes)['title']);

        $nhc = $this->ada->nhc_id;
        $this->asDesk()->postJson("/api/staff/facilities/{$this->g->id}/appointments", ['nhc_id' => $nhc, 'scheduled_at' => now()->addDay()->setTime(9, 0)->toIso8601String()])->assertCreated();
        $this->artisan('nhc:appointments:remind')->assertSuccessful();
        $this->assertSame('Visit tomorrow', end($this->pushes)['title']);
    }

    public function test_messages_between_patient_and_care_team(): void
    {
        $t = $this->asPatient()->postJson('/api/patient/threads', ['facility_id' => $this->g->id, 'subject' => 'My test results', 'body' => 'Are my results back?'])
            ->assertCreated()->json();
        $this->assertNotSame('Are my results back?', DB::table('messages')->value('body'));  // stored encrypted

        $inbox = $this->asDesk()->getJson("/api/staff/facilities/{$this->g->id}/threads")->assertOk();
        $this->assertSame([1, 'Ada Obi'], [$inbox->json('unread'), $inbox->json('threads.0.patient.name')]);
        $this->asDesk()->postJson("/api/staff/facilities/{$this->g->id}/threads/{$t['id']}/messages", ['body' => 'Yes, come in on Monday.'])->assertOk();
        $this->assertSame(['General Hospital', 'Yes, come in on Monday.'], [end($this->pushes)['title'], end($this->pushes)['body']]);

        $mine = $this->asPatient()->getJson('/api/patient/threads')->assertOk()->json('threads.0');
        $this->assertSame(1, $mine['unread']);
        $thread = $this->asPatient()->getJson("/api/patient/threads/{$t['id']}")->assertOk()->json();
        $this->assertSame(['patient', 'staff'], array_column($thread['messages'], 'from'));
        $this->assertSame(0, $this->asPatient()->getJson('/api/patient/threads')->json('threads.0.unread'));

        // A doctor (messages.send) can answer; other hospitals can't see it.
        $this->fresh()->withToken($this->doctor)->getJson("/api/staff/facilities/{$this->g->id}/threads/{$t['id']}")->assertOk();
        $this->asPatient()->postJson('/api/patient/threads', ['facility_id' => $this->other->id, 'subject' => 'Hi', 'body' => 'Hi'])->assertUnprocessable();
    }
}
