<?php

namespace Tests\Feature\Auth;

use App\Mail\SignInCode;
use App\Models\Facility;
use App\Models\FacilityMembership;
use App\Models\PatientFacilityLink;
use App\Models\User;
use App\Modules\RecordSharing\RecordRequest;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

abstract class AuthTestCase extends TestCase
{
    use DatabaseMigrations;

    protected ?string $lastSms = null;

    /** @var list<Facility> */
    protected array $facilities = [];

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.termii' => [
            'api_key' => 'test', 'sender_id' => 'NHC', 'base_url' => 'https://termii.test', 'channel' => 'generic',
        ]]);
        Http::fake(['termii.test/*' => function ($request) {
            $this->lastSms = $request['sms'];

            return Http::response(['message' => 'Successfully Sent'], 200);
        }]);
        Mail::fake();
        Queue::fake();
    }

    protected function tearDown(): void
    {
        tenancy()->end();
        // A hospital with record requests can't be deleted (restrict), so clear them first.
        RecordRequest::query()->delete();
        // Clinical rows refuse deletion through their models (kept for life), so clear the tables directly.
        DB::table('clinical_notes')->whereNotNull('amends_id')->delete();
        foreach (['emergency_accesses', 'patient_facts', 'vaccinations', 'prescriptions', 'allergies', 'clinical_notes', 'diagnoses', 'vitals', 'visits'] as $table) {
            DB::table($table)->delete();
        }
        PatientFacilityLink::withoutGlobalScopes()->delete();
        foreach ($this->facilities as $facility) {
            $facility->delete();
        }

        parent::tearDown();
    }

    protected function hospital(string $name = 'General Hospital', string $status = 'active', string $type = 'hospital'): Facility
    {
        return $this->facilities[] = Facility::create(['name' => $name, 'type' => $type, 'status' => $status]);
    }

    protected function staff(Facility $facility, string $role, string $email): User
    {
        $user = User::create(['name' => ucfirst($role), 'email' => $email]);
        FacilityMembership::create(['user_id' => $user->id, 'facility_id' => $facility->id, 'role' => $role, 'status' => 'active']);

        return $user;
    }

    protected function staffToken(User $user): string
    {
        return $user->createToken('test', ['staff'])->plainTextToken;
    }

    /** A new request with no sign-in carried over from the last one. */
    protected function fresh(): static
    {
        $this->app['auth']->forgetGuards();

        return $this->flushHeaders();
    }

    protected function smsCode(): string
    {
        preg_match('/\b(\d{6})\b/', $this->lastSms, $m);

        return $m[1];
    }

    protected function emailCode(string $to): string
    {
        $code = null;
        Mail::assertSent(SignInCode::class, function (SignInCode $mail) use ($to, &$code) {
            if ($mail->hasTo($to)) {
                $code = $mail->code;
            }

            return $mail->hasTo($to);
        });

        return $code;
    }
}
