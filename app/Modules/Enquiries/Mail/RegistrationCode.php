<?php

namespace App\Modules\Enquiries\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** The code that proves the email of someone registering a facility. */
class RegistrationCode extends Mailable
{
    public function __construct(public string $code, public string $name, public string $facility) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: "Your NHC registration code: {$this->code}");
    }

    public function content(): Content
    {
        return new Content(view: 'mail.html.registration-code', text: 'mail.registration-code');
    }
}
