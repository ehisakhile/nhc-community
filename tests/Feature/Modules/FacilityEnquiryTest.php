<?php

namespace Tests\Feature\Modules;

use App\Modules\Enquiries\FacilityEnquiry;
use App\Modules\Enquiries\NewFacilityEnquiry;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class FacilityEnquiryTest extends TestCase
{
    use DatabaseMigrations;

    private function form(array $over = []): array
    {
        return array_merge([
            'facility_name' => 'Grace Community Hospital', 'facility_type' => 'Clinic',
            'contact_name' => 'Ada Obi', 'email' => 'ada@grace.example', 'phone' => '08062345678',
            'city' => 'Ikeja', 'state' => 'Lagos', 'department_count' => '6-10',
        ], $over);
    }

    public function test_an_enquiry_is_saved_and_the_team_is_emailed(): void
    {
        Mail::fake();
        config(['nhc.enquiries_to' => 'team@example.com']);

        $id = $this->postJson('/api/facility-enquiries', $this->form())->assertCreated()->json('id');

        $e = FacilityEnquiry::findOrFail($id);
        $this->assertSame(['Grace Community Hospital', 'new'], [$e->facility_name, $e->fresh()->status]);
        Mail::assertSent(NewFacilityEnquiry::class, fn ($m) => $m->hasTo('team@example.com') && $m->hasReplyTo('ada@grace.example'));

    }

    public function test_it_is_saved_even_without_a_team_address(): void
    {
        Mail::fake();
        config(['nhc.enquiries_to' => null]);
        $this->postJson('/api/facility-enquiries', $this->form())->assertCreated();
        $this->assertSame(1, FacilityEnquiry::count());
        Mail::assertNothingSent();
    }

    public function test_bad_details_are_refused_and_status_cannot_be_set(): void
    {
        $this->postJson('/api/facility-enquiries', $this->form(['facility_type' => 'Spa', 'email' => 'x']))
            ->assertUnprocessable()->assertJsonValidationErrors(['facility_type', 'email']);

        $id = $this->postJson('/api/facility-enquiries', $this->form(['status' => 'onboarded']))->json('id');
        $this->assertSame('new', FacilityEnquiry::find($id)->fresh()->status);
    }
}
