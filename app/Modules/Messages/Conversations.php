<?php

namespace App\Modules\Messages;

use App\Models\Facility;
use App\Models\Patient;
use App\Models\User;
use App\Modules\FeatureHub\Features;
use App\Modules\Patients\FacilityJoining;
use App\Support\TellPatient;
use Illuminate\Support\Str;

/**
 * Patients write to a facility they've joined; its staff (messages.send)
 * reply from the portal. The patient gets a push for each reply. Not for
 * emergencies: the app says so and points to SOS.
 */
class Conversations
{
    public function __construct(private Features $features, private FacilityJoining $joining) {}

    public function reachable(Patient $patient): array
    {
        $ids = $this->joining->ownLinks($patient)->pluck('facility_id');

        return Facility::whereIn('id', $ids)->where('status', 'active')->get()
            ->filter(fn ($f) => $this->features->enabled($f, 'messaging'))->pluck('id')->all();
    }

    public function start(Patient $patient, Facility $facility, string $subject, string $body): MessageThread
    {
        abort_unless(in_array($facility->id, $this->reachable($patient), true), 422, "You can message hospitals you've joined that take messages on NHC.");
        $t = MessageThread::create(['facility_id' => $facility->id, 'patient_id' => $patient->id, 'subject' => Str::limit(trim($subject), 140, ''),
            'last_message_at' => now()]);

        return $this->fromPatient($t, $patient, $body);
    }

    public function fromPatient(MessageThread $t, Patient $patient, string $body): MessageThread
    {
        $t->messages()->create(['sender' => 'patient', 'sender_name' => trim($patient->first_name.' '.$patient->last_name), 'body' => trim($body), 'created_at' => now()]);
        $t->update(['status' => 'open', 'staff_unread' => $t->staff_unread + 1, 'patient_unread' => 0, 'last_message_at' => now()]);

        return $t;
    }

    public function fromStaff(MessageThread $t, User $staff, string $body): MessageThread
    {
        $t->messages()->create(['sender' => 'staff', 'staff_user_id' => $staff->id, 'sender_name' => $staff->name, 'body' => trim($body), 'created_at' => now()]);
        $t->update(['status' => 'open', 'patient_unread' => $t->patient_unread + 1, 'staff_unread' => 0, 'last_message_at' => now()]);
        TellPatient::send($t->patient, $t->facility->name, Str::limit(trim($body), 120), ['thread_id' => $t->id]);

        return $t;
    }

    public static function threadOut(MessageThread $t, bool $forStaff = false, bool $withMessages = false): array
    {
        $last = $t->relationLoaded('messages') ? $t->messages->last() : $t->messages()->latest('created_at')->first();

        return [
            'id' => $t->id, 'subject' => $t->subject, 'status' => $t->status,
            'unread' => $forStaff ? $t->staff_unread : $t->patient_unread,
            'last_message_at' => $t->last_message_at?->toIso8601String(),
            'last' => $last ? ['from' => $last->sender, 'name' => $last->sender_name, 'text' => Str::limit($last->body, 120)] : null,
            'facility' => ['id' => $t->facility->id, 'name' => $t->facility->name],
            ...($forStaff ? ['patient' => ['nhc_id' => $t->patient->nhc_id, 'name' => trim($t->patient->first_name.' '.$t->patient->last_name)]] : []),
            ...($withMessages ? ['messages' => $t->messages->map(fn ($m) => [
                'id' => $m->id, 'from' => $m->sender, 'name' => $m->sender_name, 'text' => $m->body, 'at' => $m->created_at?->toIso8601String(),
            ])->values()] : []),
        ];
    }
}
