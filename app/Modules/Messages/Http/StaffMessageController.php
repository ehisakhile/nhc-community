<?php

namespace App\Modules\Messages\Http;

use App\Http\Controllers\Controller;
use App\Models\Facility;
use App\Modules\Messages\Conversations;
use App\Modules\Messages\MessageThread;
use App\Modules\StaffAccess;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** The facility's inbox: patients' messages, answered by staff who can message. */
class StaffMessageController extends Controller
{
    use StaffAccess;

    public function __construct(private Conversations $talk) {}

    private function mine(Facility $facility, string $id): MessageThread
    {
        return MessageThread::with(['facility', 'patient', 'messages'])->where('facility_id', $facility->id)->findOrFail($id);
    }

    public function index(Request $request, Facility $facility)
    {
        $this->requirePermission($request, $facility, 'messages.send', "Your role doesn't answer patients' messages.");
        $view = $request->validate(['view' => ['nullable', Rule::in(['open', 'closed'])]])['view'] ?? 'open';

        return [
            'threads' => MessageThread::with(['facility', 'patient'])->where('facility_id', $facility->id)->where('status', $view)
                ->latest('last_message_at')->limit(200)->get()->map(fn ($t) => Conversations::threadOut($t, true))->values(),
            'unread' => (int) MessageThread::where('facility_id', $facility->id)->where('status', 'open')->sum('staff_unread'),
        ];
    }

    public function show(Request $request, Facility $facility, string $id)
    {
        $this->requirePermission($request, $facility, 'messages.send', "Your role doesn't answer patients' messages.");
        $t = $this->mine($facility, $id);
        $t->update(['staff_unread' => 0]);

        return Conversations::threadOut($t, true, true);
    }

    public function reply(Request $request, Facility $facility, string $id)
    {
        $this->requirePermission($request, $facility, 'messages.send', "Your role doesn't answer patients' messages.");
        $body = $request->validate(['body' => ['required', 'string', 'min:1', 'max:4000']])['body'];
        $t = $this->talk->fromStaff($this->mine($facility, $id), $request->user(), $body);

        return Conversations::threadOut($t->load('messages'), true, true);
    }

    public function close(Request $request, Facility $facility, string $id, string $action)
    {
        $this->requirePermission($request, $facility, 'messages.send', "Your role doesn't answer patients' messages.");
        $t = $this->mine($facility, $id);
        $t->update(['status' => $action === 'close' ? 'closed' : 'open']);

        return Conversations::threadOut($t, true, true);
    }
}
