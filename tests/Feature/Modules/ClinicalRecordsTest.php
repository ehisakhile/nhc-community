<?php

namespace Tests\Feature\Modules;

use App\Exceptions\ImmutableRecord;
use App\Models\Facility;
use App\Models\Patient;
use App\Models\User;
use App\Modules\ClinicalRecords\Models\ClinicalNote;
use App\Modules\ClinicalRecords\Models\Visit;
use App\Modules\FeatureHub\Features;
use App\Modules\Patients\FacilityJoining;
use App\Modules\RecordSharing\RecordRequest;
use App\Services\PatientIdentity;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Auth\AuthTestCase;

/** General Hospital (G) writes Ada's record. Neurosurgery Hospital (N) may only see what Ada and G share. */
class ClinicalRecordsTest extends AuthTestCase
{
    private Facility $g;

    private Facility $n;

    private Patient $ada;

    private User $doctorG;

    private User $doctorN;

    private string $tokG;

    private string $tokN;

    protected function setUp(): void
    {
        parent::setUp();
        $this->g = $this->hospital('General Hospital');
        $this->n = $this->hospital('Neurosurgery Hospital');
        $this->ada = app(PatientIdentity::class)->registerAccountHolder('08062345678', ['first_name' => 'Ada', 'last_name' => 'Obi']);

        $this->doctorG = $this->staff($this->g, 'doctor', 'doc@g.example');
        $this->doctorN = $this->staff($this->n, 'doctor', 'doc@n.example');
        app(FacilityJoining::class)->link($this->ada, $this->g, $this->doctorG);
        app(FacilityJoining::class)->link($this->ada, $this->n, $this->doctorN);
        $this->tokG = $this->staffToken($this->doctorG);
        $this->tokN = $this->staffToken($this->doctorN);
    }

    private function url(Facility $f, string $path = ''): string
    {
        return "/api/staff/facilities/{$f->id}/patients/NHC-8062345678/records".$path;
    }

    private function as(string $token): static
    {
        return $this->fresh()->withToken($token);
    }

    /** G sees Ada: a visit with vitals, a note, a diagnosis, an allergy, a prescription, blood group. */
    private function gWritesAVisit(): string
    {
        $visit = $this->as($this->tokG)->postJson($this->url($this->g, '/visits'), ['type' => 'outpatient', 'reason' => 'Headaches'])
            ->assertCreated()->json('id');
        $this->as($this->tokG)->postJson($this->url($this->g, "/visits/$visit/vitals"), ['bp_systolic' => 150, 'bp_diastolic' => 95, 'pulse_bpm' => 80])->assertCreated();
        $this->as($this->tokG)->postJson($this->url($this->g, "/visits/$visit/notes"), ['kind' => 'consultation', 'body' => 'Two weeks of morning headaches. BP raised.'])->assertCreated();
        $this->as($this->tokG)->postJson($this->url($this->g, '/diagnoses'), ['visit_id' => $visit, 'code' => 'i10', 'description' => 'Hypertension', 'chronic' => true])
            ->assertCreated()->assertJsonPath('code', 'I10');
        $this->as($this->tokG)->postJson($this->url($this->g, '/allergies'), ['substance' => 'Penicillin', 'reaction' => 'Rash', 'severity' => 'moderate'])->assertCreated();
        $this->as($this->tokG)->postJson($this->url($this->g, "/visits/$visit/prescriptions"), ['drug' => 'Amlodipine', 'strength' => '5mg', 'dose' => '1 tablet', 'frequency' => 'once daily', 'duration_days' => 30])->assertCreated();
        $this->as($this->tokG)->postJson($this->url($this->g, '/facts'), ['kind' => 'blood_group', 'value' => 'O+'])->assertCreated();

        return $visit;
    }

    private function grant(array $types, string $access = 'view', ?string $expires = '+14 days', ?string $released = 'now'): RecordRequest
    {
        return RecordRequest::create([
            'patient_id' => $this->ada->id, 'requesting_facility_id' => $this->n->id, 'holding_facility_id' => $this->g->id,
            'requested_by' => $this->doctorN->id, 'reason' => 'Surgery planning', 'record_types' => $types, 'access_type' => $access,
            'granted_record_types' => $types, 'granted_access_type' => $access, 'status' => 'approved',
            'released_at' => $released ? now()->modify($released) : null, 'expires_at' => $expires ? now()->modify($expires) : null,
        ]);
    }

    public function test_a_doctor_writes_and_reads_their_own_hospitals_record(): void
    {
        $this->gWritesAVisit();

        $r = $this->as($this->tokG)->getJson($this->url($this->g))->assertOk();
        $r->assertJsonPath('summary.allergies.0.substance', 'Penicillin')
            ->assertJsonPath('summary.conditions.0.description', 'Hypertension')
            ->assertJsonPath('summary.current_medicines.0.drug', 'Amlodipine')
            ->assertJsonPath('summary.blood_group.value', 'O+')
            ->assertJsonPath('visits.0.vitals.0.bp_systolic', 150)
            ->assertJsonPath('visits.0.notes.0.body', 'Two weeks of morning headaches. BP raised.')
            ->assertJsonPath('visits.0.hospital.name', 'General Hospital')
            ->assertJsonPath('not_available_yet', ['lab_results', 'imaging']);

        // Notes are encrypted at rest.
        $this->assertStringNotContainsString('headaches', DB::table('clinical_notes')->value('body'));
    }

    public function test_another_hospital_sees_nothing_without_a_grant(): void
    {
        $this->gWritesAVisit();

        $r = $this->as($this->tokN)->getJson($this->url($this->n))->assertOk();
        $this->assertSame([], $r->json('visits'));
        $this->assertSame([], $r->json('summary.allergies'));
        $this->assertNull($r->json('summary.blood_group'));
    }

    public function test_a_grant_shows_only_the_granted_types_and_ends_on_time(): void
    {
        $this->gWritesAVisit();
        $grant = $this->grant(['summary']);

        $r = $this->as($this->tokN)->getJson($this->url($this->n))->assertOk();
        $r->assertJsonPath('summary.allergies.0.substance', 'Penicillin');
        $this->assertSame([], $r->json('visits'));                 // consultations weren't granted

        $grant->update(['expires_at' => now()->subMinute()]);
        $this->assertSame([], $this->as($this->tokN)->getJson($this->url($this->n))->json('summary.allergies'));
    }

    public function test_a_copy_covers_what_was_written_up_to_its_release(): void
    {
        $this->travel(-2)->days();
        $this->gWritesAVisit();
        $this->travelBack();
        $this->grant(['consultations', 'summary'], access: 'copy', expires: null, released: '-1 day');
        $this->gWritesAVisit();  // after the release: not in the copy

        $visits = $this->as($this->tokN)->getJson($this->url($this->n))->json('visits');
        $this->assertCount(1, $visits);
    }

    public function test_a_withdrawn_grant_shows_nothing(): void
    {
        $this->gWritesAVisit();
        $this->grant(['summary', 'consultations'])->update(['status' => 'withdrawn']);

        $this->assertSame([], $this->as($this->tokN)->getJson($this->url($this->n))->json('visits'));
    }

    public function test_n_cannot_write_into_or_change_gs_record(): void
    {
        $visit = $this->gWritesAVisit();

        $this->as($this->tokN)->postJson($this->url($this->n, "/visits/$visit/notes"), ['kind' => 'consultation', 'body' => 'x'])->assertNotFound();
        $note = ClinicalNote::withoutGlobalScopes()->first();
        $this->as($this->tokN)->postJson($this->url($this->n, "/notes/{$note->id}/entered-in-error"), ['reason' => 'Not ours'])->assertNotFound();
    }

    public function test_a_patient_who_has_not_joined_is_not_found(): void
    {
        $other = $this->hospital('Clinic Three');
        $doc = $this->staffToken($this->staff($other, 'doctor', 'doc@three.example'));

        $this->as($doc)->getJson($this->url($other))->assertNotFound();
    }

    public function test_roles_see_and_do_only_their_part(): void
    {
        $visit = $this->gWritesAVisit();
        $pharm = $this->staffToken($this->staff($this->g, 'pharmacist', 'pharm@g.example'));
        $desk = $this->staffToken($this->staff($this->g, 'front_desk', 'desk@g.example'));

        $r = $this->as($pharm)->getJson($this->url($this->g))->assertOk();
        $this->assertSame([], $r->json('visits.0.notes'));          // no consultation notes for pharmacy
        $rx = $r->json('visits.0.prescriptions.0.id');
        $this->as($pharm)->postJson($this->url($this->g, "/prescriptions/$rx/dispense"))->assertOk()->assertJsonPath('dispensed_at', fn ($v) => $v !== null);
        $this->as($pharm)->postJson($this->url($this->g, "/visits/$visit/notes"), ['kind' => 'consultation', 'body' => 'x'])->assertForbidden();

        $this->as($desk)->getJson($this->url($this->g))->assertForbidden();
    }

    public function test_the_pharmacy_queue_lists_what_is_waiting_to_be_dispensed(): void
    {
        $this->gWritesAVisit();
        $pharm = $this->staffToken($this->staff($this->g, 'pharmacist', 'pharm@g.example'));
        $queue = "/api/staff/facilities/{$this->g->id}/prescriptions";

        $rx = $this->as($pharm)->getJson($queue)->assertOk()->assertJsonPath('0.drug', 'Amlodipine')->assertJsonPath('0.patient.nhc_id', 'NHC-8062345678')->json('0.id');
        $this->as($pharm)->postJson($this->url($this->g, "/prescriptions/$rx/dispense"))->assertOk();

        $this->as($pharm)->getJson($queue)->assertOk()->assertJsonCount(0);
        $this->as($pharm)->getJson($queue.'?status=dispensed')->assertOk()->assertJsonCount(1);
        // N's pharmacy never sees G's prescriptions.
        $this->as($this->staffToken($this->staff($this->n, 'pharmacist', 'pharm@n.example')))->getJson("/api/staff/facilities/{$this->n->id}/prescriptions")->assertJsonCount(0);
    }

    public function test_history_is_never_rewritten(): void
    {
        $visit = $this->gWritesAVisit();
        $note = ClinicalNote::withoutGlobalScopes()->first();

        try {
            $note->update(['body' => 'changed']);
            $this->fail('A note was rewritten.');
        } catch (ImmutableRecord) {
        }
        try {
            $note->delete();
            $this->fail('A note was deleted.');
        } catch (ImmutableRecord) {
        }

        // A correction is added, and the original shows it was amended.
        $this->as($this->tokG)->postJson($this->url($this->g, "/visits/$visit/notes"), ['kind' => 'consultation', 'body' => 'Correction: one week, not two.', 'amends_id' => $note->id])->assertCreated();
        $notes = $this->as($this->tokG)->getJson($this->url($this->g))->json('visits.0.notes');
        $this->assertCount(1, $notes[0]['amended_by']);

        // A wrong row is marked, not removed.
        $this->as($this->tokG)->postJson($this->url($this->g, "/notes/{$note->id}/entered-in-error"), ['reason' => 'Wrong patient'])->assertOk();
        $this->assertSame('Wrong patient', $this->as($this->tokG)->getJson($this->url($this->g))->json('visits.0.notes.0.entered_in_error.reason'));
    }

    public function test_a_closed_visit_takes_only_amendments(): void
    {
        $visit = $this->gWritesAVisit();
        $this->as($this->tokG)->postJson($this->url($this->g, "/visits/$visit/close"))->assertOk()->assertJsonPath('status', 'closed');

        $this->as($this->tokG)->postJson($this->url($this->g, "/visits/$visit/notes"), ['kind' => 'progress', 'body' => 'Late note'])->assertUnprocessable();
        $this->as($this->tokG)->postJson($this->url($this->g, "/visits/$visit/vitals"), ['pulse_bpm' => 70])->assertUnprocessable();
    }

    public function test_emergency_access_shows_every_hospitals_summary_and_tells_the_patient(): void
    {
        $this->gWritesAVisit();
        $er = $this->hospital('Roadside Emergency Centre');
        $doc = $this->staffToken($this->staff($er, 'doctor', 'er@example.com'));

        // Ada hasn't joined this hospital; ordinary reads are refused.
        $this->as($doc)->getJson($this->url($er))->assertNotFound();

        $this->as($doc)->postJson($this->url($er, '/emergency-access'), ['reason' => 'Unconscious after a road accident'])
            ->assertOk()->assertJsonPath('summary.allergies.0.substance', 'Penicillin')->assertJsonPath('summary.blood_group.value', 'O+');
        $this->assertStringContainsString('Roadside Emergency Centre opened your health summary', $this->lastSms);

        // Ada sees it on her access screen.
        $token = $this->ada->createToken('t', ['patient'])->plainTextToken;
        $access = $this->fresh()->withToken($token)->getJson('/api/patient/records/access')->assertOk()->json();
        $this->assertTrue(collect($access)->contains(fn ($a) => $a['emergency'] && $a['hospital'] === 'Roadside Emergency Centre' && $a['reason'] === 'Unconscious after a road accident'));
    }

    public function test_the_patient_sees_everything_and_can_add_an_allergy(): void
    {
        $this->gWritesAVisit();
        $visitN = $this->as($this->tokN)->postJson($this->url($this->n, '/visits'), ['type' => 'outpatient'])->json('id');
        $this->as($this->tokN)->postJson($this->url($this->n, "/visits/$visitN/notes"), ['kind' => 'consultation', 'body' => 'Neuro review'])->assertCreated();

        $token = $this->ada->createToken('t', ['patient'])->plainTextToken;
        $mine = $this->fresh()->withToken($token)->getJson('/api/patient/records')->assertOk();
        $this->assertEqualsCanonicalizing(['General Hospital', 'Neurosurgery Hospital'], collect($mine->json('visits'))->pluck('hospital.name')->all());
        $this->assertArrayNotHasKey('sources', $mine->json());

        $id = $this->fresh()->withToken($token)->postJson('/api/patient/records/allergies', ['substance' => 'Groundnuts'])
            ->assertCreated()->assertJsonPath('source', 'patient')->assertJsonPath('confirmed', false)->json('id');

        // G's doctor sees it in the summary and confirms it.
        $this->as($this->tokG)->postJson($this->url($this->g, "/allergies/$id"), ['action' => 'confirm'])->assertOk()->assertJsonPath('confirmed', true);
    }

    public function test_staff_cannot_use_the_patient_routes_or_reach_rows_without_a_hospital(): void
    {
        $this->gWritesAVisit();
        $this->as($this->tokG)->getJson('/api/patient/records')->assertForbidden();

        // A forgotten filter returns nothing rather than everything.
        $this->assertSame(0, Visit::count());
    }

    public function test_switching_clinical_records_off_closes_the_routes(): void
    {
        $admin = $this->staffToken($this->staff($this->g, 'admin', 'admin@g.example'));
        // Prescriptions depends on clinical records, so it goes first.
        $this->as($admin)->putJson("/api/staff/facilities/{$this->g->id}/features/clinical_records", ['enabled' => false])->assertUnprocessable();
        $this->as($admin)->putJson("/api/staff/facilities/{$this->g->id}/features/prescriptions", ['enabled' => false])->assertOk();
        $this->as($admin)->putJson("/api/staff/facilities/{$this->g->id}/features/clinical_records", ['enabled' => false])->assertOk();
        app()->forgetInstance(Features::class);

        $this->as($this->tokG)->getJson($this->url($this->g))->assertForbidden();
    }
}
