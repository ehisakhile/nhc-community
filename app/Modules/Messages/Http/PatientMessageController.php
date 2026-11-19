<?php

namespace App\Modules\Messages\Http;

use App\Http\Controllers\Controller;
use App\Models\Facility;
use App\Modules\Messages\Conversations;
use App\Modules\Messages\MessageThread;
use Illuminate\Http\Request;

/** The patient's messages with their hospitals, from the Hospify Citizen app. */
class PatientMessageController extends Controller
{
    public function __construct(private Conversations $talk) {}

    private function mine(Request $request, string $id): MessageThread
    {
        return MessageThread::with(['facility', 'messages'])->where('patient_id', $request->user()->id)->findOrFail($id);
    }

    public function index(Request $request)
    {
        return [
            'threads' => MessageThread::with('facility')->where('patient_id', $request->user()->id)->latest('last_message_at')->limit(100)->get()
                ->map(fn ($t) => Conversations::threadOut($t))->values(),
            'reachable_facility_ids' => $this->talk->reachable($request->user()),
        ];
    }

    public function store(Request $request)
    {
        $d = $request->validate([
            'facility_id' => ['required', 'string'],
            'subject' => ['required', 'string', 'min:2', 'max:140'],
            'body' => ['required', 'string', 'min:1', 'max:4000'],
        ]);
        $t = $this->talk->start($request->user(), Facility::findOrFail($d['facility_id']), $d['subject'], $d['body']);

        return response()->json(Conversations::threadOut($t->load(['facility', 'messages']), withMessages: true), 201);
    }

    public function show(Request $request, string $id)
    {
        $t = $this->mine($request, $id);
        $t->update(['patient_unread' => 0]);

        return Conversations::threadOut($t, withMessages: true);
    }

    public function reply(Request $request, string $id)
    {
        $body = $request->validate(['body' => ['required', 'string', 'min:1', 'max:4000']])['body'];
        $t = $this->talk->fromPatient($this->mine($request, $id), $request->user(), $body);

        return Conversations::threadOut($t->load(['facility', 'messages']), withMessages: true);
    }
}
