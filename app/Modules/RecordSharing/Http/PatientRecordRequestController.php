<?php

namespace App\Modules\RecordSharing\Http;

use App\Http\Controllers\Controller;
use App\Modules\RecordSharing\RecordRequest;
use App\Modules\RecordSharing\RecordRequests;
use App\Modules\RecordSharing\RecordRequestView;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** The patient's side, in the Hospify Citizen app: see who's asking, approve (narrowing if they like), decline, withdraw. */
class PatientRecordRequestController extends Controller
{
    public function __construct(private RecordRequests $requests) {}

    public function index(Request $request)
    {
        return RecordRequest::with(['patient', 'requester', 'holder', 'requestedBy'])
            ->where('patient_id', $request->user()->id)->where('status', '!=', 'cancelled')
            ->latest()->limit(100)->get()
            ->map(fn ($r) => RecordRequestView::of($r, withPatient: false))->values();
    }

    public function approve(Request $request, string $id)
    {
        $data = $request->validate([
            'record_types' => ['nullable', 'array', 'min:1'],
            'record_types.*' => ['string', Rule::in(array_keys(config('nhc.record_types')))],
            'access_type' => ['nullable', Rule::in(['view', 'copy'])],
            'duration_days' => ['nullable', 'integer', 'min:1', 'max:'.config('nhc.record_access.max_days')],
        ]);

        $r = $this->requests->patientDecision($this->mine($request, $id), true, $data, 'app');

        return RecordRequestView::of($r->fresh(['patient', 'requester', 'holder', 'requestedBy']), withPatient: false);
    }

    public function decline(Request $request, string $id)
    {
        $r = $this->requests->patientDecision($this->mine($request, $id), false, [], 'app');

        return RecordRequestView::of($r->fresh(['patient', 'requester', 'holder', 'requestedBy']), withPatient: false);
    }

    public function withdraw(Request $request, string $id)
    {
        $r = $this->requests->withdraw($this->mine($request, $id));

        return RecordRequestView::of($r->fresh(['patient', 'requester', 'holder', 'requestedBy']), withPatient: false);
    }

    private function mine(Request $request, string $id): RecordRequest
    {
        return RecordRequest::with(['patient', 'requester', 'holder'])->where('patient_id', $request->user()->id)->findOrFail($id);
    }
}
