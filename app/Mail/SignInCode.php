<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class SignInCode extends Mailable
{
    use Queueable;

    public function __construct(public string $code, public string $name) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: "Your NHC sign-in code: {$this->code}");
    }

    public function content(): Content
    {
        return new Content(view: 'mail.html.sign-in-code', text: 'mail.sign-in-code');
    }
}
