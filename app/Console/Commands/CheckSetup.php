<?php

namespace App\Console\Commands;

use App\Services\Termii;
use App\Support\NigerianPhone;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/** Checks the server's settings and the services NHC depends on. Changes nothing. */
#[Signature('nhc:check {--sms= : Also send a test text to this phone number}')]
#[Description('Check NHC\'s settings: database, email, Termii, Hospify Connect, LoggerAI')]
class CheckSetup extends Command
{
    private bool $failed = false;

    public function handle(Termii $termii): int
    {
        $this->line('<options=bold>NHC setup check</>');

        $this->row('Environment', config('app.env') === 'production', config('app.env'), 'set APP_ENV=production');
        $this->row('Debug mode off', ! config('app.debug'), config('app.debug') ? 'ON' : 'off', 'set APP_DEBUG=false');
        $this->row('Logs reach `docker compose logs`', config('logging.default') === 'stderr', config('logging.default'), 'set LOG_CHANNEL=stderr');

        try {
            DB::connection()->getPdo();
            $this->row('Central database', true, config('database.connections.mysql.database'));
        } catch (Throwable $e) {
            $this->row('Central database', false, $e->getMessage());
        }

        $mailer = config('mail.default');
        $this->row('Email (sign-in codes)', $mailer === 'smtp' && filled(config('mail.mailers.smtp.host')),
            $mailer.' '.config('mail.mailers.smtp.host'), 'set MAIL_MAILER=smtp and the SES MAIL_* values');

        $hasTermii = filled(config('services.termii.api_key')) && filled(config('services.termii.sender_id'));
        $apiKey = (string) config('services.termii.api_key');
        // Enough to compare with Hospify's key without showing it: length and last 4.
        $fingerprint = $apiKey === '' ? 'no key' : 'key …'.substr($apiKey, -4).' ('.strlen($apiKey).' chars'.(preg_match('/^["\'\s]|["\'\s]$/', $apiKey) ? ', has quotes or spaces!' : '').')';
        $this->row('Termii settings', $hasTermii, $fingerprint.', sender '.(config('services.termii.sender_id') ?: '—').', channel '.config('services.termii.channel').', '.config('services.termii.base_url'));
        if ($hasTermii) {
            try {
                $b = $termii->balance();
                $detail = is_array($b['body']) ? trim(($b['body']['balance'] ?? '?').' '.($b['body']['currency'] ?? '')) : (string) $b['body'];
                $this->row('Termii account', $b['ok'], $b['ok'] ? "balance {$detail}" : "HTTP {$b['status']}: ".mb_substr($detail, 0, 120));
            } catch (Throwable $e) {
                $this->row('Termii account', false, $e->getMessage());
            }
        }

        // Hospify Connect is optional: only check the key if one is set.
        $key = config('services.hospify.public_key');
        if ($key) {
            $pem = ! str_contains($key, 'BEGIN') ? base64_decode($key, true) : $key;
            $this->row('Hospify Connect key', $pem && openssl_pkey_get_public($pem) !== false, 'set', 'check HOSPIFY_PUBLIC_KEY');
        } else {
            $this->line('  Hospify Connect: off (optional)');
        }

        $this->row('LoggerAI', filled(config('loggerai.key')) && config('loggerai.enabled'), filled(config('loggerai.key')) ? 'key set' : 'no key', 'set LOGGERAI_KEY (then: php artisan loggerai:test)');

        if ($phone = $this->option('sms')) {
            try {
                $termii->send(NigerianPhone::normalise($phone), 'NHC test message. You can ignore it.');
                $this->row('Test SMS', true, 'sent');
            } catch (Throwable $e) {
                $this->row('Test SMS', false, 'failed: see the reason above in the logs');
            }
        }

        $this->newLine();
        $this->line($this->failed ? '<fg=red>Some checks failed.</>' : '<fg=green>All good.</>');

        return $this->failed ? self::FAILURE : self::SUCCESS;
    }

    private function row(string $what, bool $ok, ?string $detail = null, ?string $fix = null): void
    {
        $this->failed = $this->failed || ! $ok;
        $this->line(sprintf('  %s %-34s %s%s', $ok ? '<fg=green>✓</>' : '<fg=red>✗</>', $what, $detail ?? '', ! $ok && $fix ? "  <fg=yellow>→ {$fix}</>" : ''));
    }
}
