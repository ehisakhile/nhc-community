<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class StaffInvite extends Mailable
{
    use Queueable;

    public function __construct(public string $name, public string $facility, public string $role) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: "You've been added to {$this->facility} on NHC");
    }

    public function content(): Content
    {
        return new Content(view: 'mail.html.staff-invite', text: 'mail.staff-invite');
    }
}
