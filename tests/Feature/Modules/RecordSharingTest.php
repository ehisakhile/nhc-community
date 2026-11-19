<?php

namespace Tests\Feature\Modules;

use App\Models\Facility;
use App\Models\Patient;
use App\Modules\FeatureHub\Features;
use App\Modules\Patients\FacilityJoining;
use App\Modules\RecordSharing\RecordRequest;
use App\Modules\RecordSharing\RecordRequests;
use App\Services\PatientIdentity;
use Tests\Feature\Auth\AuthTestCase;

/** Neurosurgery Hospital (A) asks General Hospital (B) for a patient's records. */
class RecordSharingTest extends AuthTestCase
{
    private Facility $a;

    private Facility $b;

    private Patient $patient;

    private string $doctorA;

    private string $recordsB;

    protected function setUp(): void
    {
        parent::setUp();
        $this->a = $this->hospital('Neurosurgery Hospital');
        $this->b = $this->hospital('General Hospital');
        $this->patient = app(PatientIdentity::class)->registerAccountHolder('08062345678', ['first_name' => 'Ada', 'last_name' => 'Obi']);

        $doctor = $this->staff($this->a, 'doctor', 'doc@a.example');
        $records = $this->staff($this->b, 'records_officer', 'records@b.example');
        foreach ([$this->a, $this->b] as $h) {
            app(FacilityJoining::class)->link($this->patient, $h, $h->is($this->a) ? $doctor : $records);
        }
        $this->doctorA = $this->staffToken($doctor);
        $this->recordsB = $this->staffToken($records);
    }

    private function ask(array $overrides = [])
    {
        return $this->fresh()->withToken($this->doctorA)->postJson("/api/staff/facilities/{$this->a->id}/record-requests", $overrides + [
            'nhc_id' => 'NHC-8062345678',
            'holding_facility_id' => $this->b->id,
            'record_types' => ['summary', 'imaging', 'consultations'],
            'access_type' => 'view',
            'duration_days' => 30,
            'reason' => 'Planning surgery; need the head CT and history.',
        ]);
    }

    private function patientConsentsAtDesk(string $id, array $choices = [], string $decision = 'approve')
    {
        $challenge = $this->fresh()->withToken($this->doctorA)->postJson("/api/staff/facilities/{$this->a->id}/record-requests/{$id}/consent-code")->json('challenge');

        return $this->fresh()->withToken($this->doctorA)->postJson("/api/staff/facilities/{$this->a->id}/record-requests/{$id}/consent",
            $choices + ['challenge' => $challenge, 'code' => $this->smsCode(), 'decision' => $decision]);
    }

    private function incoming()
    {
        return $this->fresh()->withToken($this->recordsB)->getJson("/api/staff/facilities/{$this->b->id}/record-requests?direction=incoming");
    }

    public function test_the_full_journey_patient_then_hospital_each_narrowing(): void
    {
        $id = $this->ask()->assertCreated()->assertJsonPath('status', 'awaiting_patient')->json('id');

        // B doesn't see it until the patient has agreed.
        $this->incoming()->assertJsonCount(0);

        // The patient agrees at A's desk, but only to the summary and imaging, for 14 days.
        $this->patientConsentsAtDesk($id, ['record_types' => ['summary', 'imaging'], 'duration_days' => 14])
            ->assertOk()->assertJsonPath('status', 'awaiting_hospital')->assertJsonPath('granted.duration_days', 14);
        $this->assertStringContainsString('Neurosurgery Hospital is asking General Hospital', $this->lastSms);

        // B releases the imaging only.
        $this->incoming()->assertJsonCount(1)->assertJsonPath('0.patient.first_name', 'Ada');
        $this->fresh()->withToken($this->recordsB)->postJson("/api/staff/facilities/{$this->b->id}/record-requests/{$id}/release", ['record_types' => ['imaging']])
            ->assertOk()->assertJsonPath('status', 'approved')->assertJsonPath('granted.record_types', ['imaging']);

        $r = RecordRequest::find($id);
        $this->assertTrue($r->expires_at->between(now()->addDays(13), now()->addDays(15)));
        $this->assertCount(1, app(RecordRequests::class)->grantsFor($this->a, $this->patient));
    }

    public function test_nobody_can_widen_what_was_asked(): void
    {
        $id = $this->ask()->json('id');

        $this->patientConsentsAtDesk($id, ['record_types' => ['summary', 'lab_results']])->assertStatus(422);
        $this->patientConsentsAtDesk($id, ['access_type' => 'copy'])->assertStatus(422);
        $this->patientConsentsAtDesk($id, ['duration_days' => 90])->assertStatus(422);
    }

    public function test_a_copy_can_be_narrowed_to_view_access(): void
    {
        $id = $this->ask(['access_type' => 'copy', 'duration_days' => null])->json('id');
        $this->patientConsentsAtDesk($id, ['access_type' => 'view', 'duration_days' => 7])
            ->assertJsonPath('granted.access_type', 'view')->assertJsonPath('granted.duration_days', 7);
    }

    public function test_the_patient_can_decline(): void
    {
        $id = $this->ask()->json('id');
        $this->patientConsentsAtDesk($id, decision: 'decline')->assertJsonPath('status', 'declined')->assertJsonPath('declined_by', 'patient');
        $this->incoming()->assertJsonCount(0);
    }

    public function test_hospital_b_can_decline_with_a_reason(): void
    {
        $id = $this->ask()->json('id');
        $this->patientConsentsAtDesk($id);

        $this->fresh()->withToken($this->recordsB)->postJson("/api/staff/facilities/{$this->b->id}/record-requests/{$id}/decline", ['reason' => 'No imaging on file.'])
            ->assertOk()->assertJsonPath('status', 'declined')->assertJsonPath('declined_by', 'hospital');
    }

    public function test_if_b_never_saw_the_patient_it_is_declined_only_after_the_patient_agrees(): void
    {
        $c = $this->hospital('Eye Clinic');
        $id = $this->ask(['holding_facility_id' => $c->id])->assertCreated()->json('id');

        $this->patientConsentsAtDesk($id)->assertJsonPath('status', 'declined')->assertJsonPath('declined_by', 'system');
    }

    public function test_the_patient_decides_and_withdraws_in_the_app(): void
    {
        $id = $this->ask()->json('id');
        $patientToken = $this->patient->createToken('app', ['patient'])->plainTextToken;

        $this->fresh()->withToken($patientToken)->getJson('/api/patient/record-requests')->assertJsonCount(1)
            ->assertJsonPath('0.requesting_hospital.name', 'Neurosurgery Hospital');
        $this->fresh()->withToken($patientToken)->postJson("/api/patient/record-requests/{$id}/approve", ['duration_days' => 10])
            ->assertJsonPath('status', 'awaiting_hospital');
        $this->fresh()->withToken($this->recordsB)->postJson("/api/staff/facilities/{$this->b->id}/record-requests/{$id}/release")->assertOk();

        $this->fresh()->withToken($patientToken)->postJson("/api/patient/record-requests/{$id}/withdraw")->assertJsonPath('status', 'withdrawn');
        $this->assertCount(0, app(RecordRequests::class)->grantsFor($this->a, $this->patient));
    }

    public function test_view_access_expires(): void
    {
        $id = $this->ask(['duration_days' => 1])->json('id');
        $this->patientConsentsAtDesk($id);
        $this->fresh()->withToken($this->recordsB)->postJson("/api/staff/facilities/{$this->b->id}/record-requests/{$id}/release");

        $this->travel(2)->days();
        $this->fresh()->withToken($this->doctorA)->getJson("/api/staff/facilities/{$this->a->id}/record-requests")->assertJsonPath('0.status', 'expired');
    }

    public function test_the_patient_must_be_onboarded_at_the_asking_hospital(): void
    {
        $other = app(PatientIdentity::class)->registerAccountHolder('08031112222', ['first_name' => 'Bola', 'last_name' => 'Ade']);
        $this->ask(['nhc_id' => $other->nhc_id])->assertStatus(422);
    }

    public function test_a_hospital_that_switched_record_sharing_off_cannot_be_asked(): void
    {
        $admin = $this->staffToken($this->staff($this->b, 'admin', 'admin@b.example'));
        $this->withToken($admin)->putJson("/api/staff/facilities/{$this->b->id}/features/record_sharing", ['enabled' => false])->assertOk();
        app()->forgetInstance(Features::class);

        $this->ask()->assertStatus(422);
        $this->fresh()->withToken($this->doctorA)->getJson("/api/staff/facilities/{$this->a->id}/record-requests/hospitals")
            ->assertJsonMissing(['name' => 'General Hospital']);
    }

    public function test_roles_without_permission_are_refused(): void
    {
        $nurse = $this->staffToken($this->staff($this->a, 'nurse', 'nurse@a.example'));
        $this->fresh()->withToken($nurse)->postJson("/api/staff/facilities/{$this->a->id}/record-requests", [])->assertForbidden();

        $doctorB = $this->staffToken($this->staff($this->b, 'doctor', 'doc@b.example'));
        $this->fresh()->withToken($doctorB)->getJson("/api/staff/facilities/{$this->b->id}/record-requests?direction=incoming")->assertForbidden();
    }

    public function test_a_consent_code_sent_by_one_doctor_cannot_be_used_by_another(): void
    {
        $id = $this->ask()->json('id');
        $challenge = $this->fresh()->withToken($this->doctorA)->postJson("/api/staff/facilities/{$this->a->id}/record-requests/{$id}/consent-code")->json('challenge');
        $other = $this->staffToken($this->staff($this->a, 'records_officer', 'rec@a.example'));

        $this->fresh()->withToken($other)->postJson("/api/staff/facilities/{$this->a->id}/record-requests/{$id}/consent",
            ['challenge' => $challenge, 'code' => $this->smsCode(), 'decision' => 'approve'])->assertStatus(422);
    }

    public function test_each_side_sees_the_request_only_when_it_should(): void
    {
        $id = $this->ask()->json('id');
        $this->fresh()->withToken($this->doctorA)->getJson("/api/staff/facilities/{$this->a->id}/record-requests/{$id}")
            ->assertOk()->assertJsonPath('side', 'outgoing');
        // B can't open it before the patient approves.
        $this->fresh()->withToken($this->recordsB)->getJson("/api/staff/facilities/{$this->b->id}/record-requests/{$id}")->assertNotFound();

        $this->patientConsentsAtDesk($id);
        $this->fresh()->withToken($this->recordsB)->getJson("/api/staff/facilities/{$this->b->id}/record-requests/{$id}")
            ->assertOk()->assertJsonPath('side', 'incoming');
    }
}
