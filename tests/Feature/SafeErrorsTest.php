<?php

namespace Tests\Feature;

use App\Exceptions\SafeException;
use App\Services\PatientIdentity;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Symfony\Component\Mailer\Exception\TransportException;
use Tests\TestCase;
use Throwable;

/** Personal data never reaches logs or LoggerAI through an error message. */
class SafeErrorsTest extends TestCase
{
    use RefreshDatabase;

    /** What the exception handler hands to reporters such as LoggerAI. */
    private function reported(Throwable $e): Throwable
    {
        $seen = null;
        $handler = $this->app->make(ExceptionHandler::class);
        $handler->reportable(function (Throwable $r) use (&$seen) {
            $seen = $r;

            return false;
        });
        $handler->report($e);

        return $seen;
    }

    public function test_a_database_error_is_reported_without_the_values(): void
    {
        $identity = app(PatientIdentity::class);
        $identity->registerAccountHolder('08062345678', ['first_name' => 'Ada', 'last_name' => 'Obi'])
            ->forceFill(['email' => 'ada@example.com'])->save();
        $second = $identity->registerAccountHolder('08031112222', ['first_name' => 'Bola', 'last_name' => 'Ade']);

        try {
            $second->forceFill(['email' => 'ada@example.com'])->save();
            $this->fail('Expected a duplicate email error.');
        } catch (QueryException $e) {
            $this->assertStringContainsString('ada@example.com', $e->getMessage());
            $reported = $this->reported($e);
        }

        $this->assertInstanceOf(SafeException::class, $reported);
        $this->assertStringContainsString('on UPDATE patients', $reported->getMessage());
        foreach (['ada@example.com', '8031112222', 'Bola', 'NHC-'] as $secret) {
            $this->assertStringNotContainsString($secret, $reported->getMessage());
        }
        // Still points at the code that failed.
        $this->assertNotEmpty($reported->getTrace());
        $this->assertNull($reported->getPrevious());
    }

    public function test_a_mail_error_is_reported_without_the_recipient(): void
    {
        $reported = $this->reported(new TransportException('550 Mailbox <ada@example.com> unavailable for NHC-8062345678 at +234 806 234 5678'));

        $this->assertSame('Mail delivery failed: 550 Mailbox <[email]> unavailable for [nhc_id] at [number]', $reported->getMessage());
    }

    public function test_other_errors_are_left_alone(): void
    {
        $e = new \RuntimeException('Hospify link failed');

        $this->assertSame($e, $this->reported($e));
    }

    public function test_the_api_docs_are_closed_outside_local(): void
    {
        $this->get('/docs/api')->assertForbidden();
        $this->get('/docs/api.json')->assertForbidden();
    }
}
