<?php

namespace App\Modules\Enquiries;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** Tells our team a hospital asked to join from the website. */
class NewFacilityEnquiry extends Mailable
{
    public function __construct(public FacilityEnquiry $enquiry) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "NHC: {$this->enquiry->facility_name} wants to join",
            replyTo: [new Address($this->enquiry->email, $this->enquiry->contact_name)],
        );
    }

    public function content(): Content
    {
        $e = $this->enquiry;

        return new Content(view: 'mail.html.team-alert', with: [
            'heading' => "{$e->facility_name} wants to join",
            'intro' => "{$e->facility_name} ({$e->facility_type}) asked to join NHC.",
            'rows' => [
                'Contact' => $e->contact_name, 'Email' => $e->email, 'Phone' => $e->phone,
                'Where' => implode(', ', array_filter([$e->city, $e->state])), 'Departments' => $e->department_count,
            ],
            'next' => 'Reply to this email to reach them.',
        ]);
    }
}
