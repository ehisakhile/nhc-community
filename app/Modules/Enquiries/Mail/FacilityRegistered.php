<?php

namespace App\Modules\Enquiries\Mail;

use App\Models\Facility;
use App\Models\User;
use App\Support\FacilityTypes;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** Tells our team a facility registered and is waiting for approval. */
class FacilityRegistered extends Mailable
{
    public function __construct(public Facility $facility, public User $admin, public array $details) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "NHC: {$this->facility->name} registered and is waiting for approval",
            replyTo: [new Address($this->admin->email, $this->admin->name)],
        );
    }

    public function content(): Content
    {
        $f = $this->facility;

        return new Content(view: 'mail.html.team-alert', with: [
            'heading' => "{$f->name} is waiting for approval",
            'intro' => "{$f->name} registered on NHC as a ".FacilityTypes::spec($f)['label'].'. It can set up now, but can\'t register patients or take orders until you approve it.',
            'rows' => [
                'Admin' => "{$this->admin->name} <{$this->admin->email}>",
                'Phone' => $f->phone,
                'Address' => $this->details['address'] ?? null,
                'Where' => implode(', ', array_filter([$f->lga, $f->state])),
            ],
            'next' => 'Approve or decline it in the Hospify admin console: NHC partners, then Registered on NHC.',
        ]);
    }
}
