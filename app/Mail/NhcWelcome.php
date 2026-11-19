<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * An invitation only. The front desk typed this address, so it may be wrong:
 * it carries no NHC ID, no hospital name and nothing about the person's health.
 */
class NhcWelcome extends Mailable
{
    use Queueable;

    public function __construct(public string $firstName) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'See your health records in the Hospify app');
    }

    public function content(): Content
    {
        return new Content(view: 'mail.html.nhc-welcome', text: 'mail.nhc-welcome');
    }
}
