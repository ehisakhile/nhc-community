<?php

namespace App\Modules\Enquiries\Mail;

use App\Models\Facility;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** Tells a facility's admin it was approved (or not). */
class FacilityDecision extends Mailable
{
    public function __construct(public Facility $facility, public string $name, public bool $approved, public ?string $note = null) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->approved ? "{$this->facility->name} is live on NHC" : "About your NHC registration for {$this->facility->name}");
    }

    public function content(): Content
    {
        return new Content(view: 'mail.html.facility-decision', text: 'mail.facility-decision');
    }
}
