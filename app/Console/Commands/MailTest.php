<?php

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;
use Throwable;

/** Sends one plain email and shows exactly what went wrong if it can't. */
#[Signature('nhc:mail-test {to : Where to send it}')]
#[Description('Check NHC can send email (sign-in codes, registrations, the team\'s alerts)')]
class MailTest extends Command
{
    public function handle(): int
    {
        $this->line('Mailer: '.config('mail.default').', host: '.(config('mail.mailers.'.config('mail.default').'.host') ?? '-')
            .', from: '.config('mail.from.address'));
        $this->line('Team alerts go to: '.(config('nhc.enquiries_to') ?: 'nobody (NHC_ENQUIRIES_TO is empty)'));
        try {
            Mail::raw('This is a test from NHC. If you can read it, NHC can send email.', fn ($m) => $m->to($this->argument('to'))->subject('NHC email test'));
        } catch (Throwable $e) {
            $this->error('Not sent: '.$e->getMessage());

            return self::FAILURE;
        }
        $this->info('Sent to '.$this->argument('to').'. Check the inbox and the spam folder.');

        return self::SUCCESS;
    }
}
