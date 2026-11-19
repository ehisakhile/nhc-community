<?php

namespace App\Modules\RecordSharing\Http;

use App\Http\Controllers\Controller;
use App\Models\Facility;
use App\Models\Patient;
use App\Modules\FeatureHub\Features;
use App\Modules\RecordSharing\RecordRequest;
use App\Modules\RecordSharing\RecordRequests;
use App\Modules\RecordSharing\RecordRequestView;
use App\Modules\StaffAccess;
use App\Services\OneTimeCodes;
use App\Services\Termii;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Staff side. Hospital A asks and takes the patient's consent at its desk; hospital B releases. */
class StaffRecordRequestController extends Controller
{
    use StaffAccess;

    public function __construct(private RecordRequests $requests) {}

    /** Other hospitals that share records on NHC (not which ones the patient attended). */
    public function hospitals(Request $request, Facility $facility, Features $features)
    {
        $this->requirePermission($request, $facility, 'records.request');
        $search = trim((string) $request->query('search', ''));

        return Facility::where('status', 'active')->whereKeyNot($facility->id)
            ->when($search !== '', fn ($q) => $q->where('name', 'like', '%'.addcslashes($search, '%_\\').'%'))
            ->orderBy('name')->limit(50)->get()
            ->filter(fn (Facility $f) => $features->enabled($f, 'record_sharing'))
            ->map(fn (Facility $f) => ['id' => $f->id, 'name' => $f->name, 'type' => $f->type, 'state' => $f->state])
            ->values();
    }

    /** ?direction=outgoing (what we asked for) or incoming (what others ask us for, once the patient approved). */
    public function index(Request $request, Facility $facility)
    {
        $incoming = $request->query('direction') === 'incoming';
        $this->requirePermission($request, $facility, $incoming ? 'records.release' : 'records.request');

        $query = RecordRequest::with(['patient', 'requester', 'holder', 'requestedBy'])->latest();
        if ($incoming) {
            // B sees a request only once the patient approved, and never one the
            // patient or NHC declined before it reached B.
            $query->where('holding_facility_id', $facility->id)
                ->whereNotNull('patient_decided_at')
                ->where(fn ($q) => $q->whereNull('declined_by')->orWhere('declined_by', 'hospital'));
        } else {
            $query->where('requesting_facility_id', $facility->id);
        }

        return $query->limit(200)->get()->map(fn ($r) => RecordRequestView::of($r))->values();
    }

    /** One request, for the hospital that asked or (once the patient approved) the one asked. */
    public function show(Request $request, Facility $facility, string $id)
    {
        $member = $this->requirePermission($request, $facility, 'patients.view');
        $r = RecordRequest::with(['patient', 'requester', 'holder', 'requestedBy'])->findOrFail($id);

        $asker = $r->requesting_facility_id === $facility->id && $member->permits('records.request');
        $holder = $r->holding_facility_id === $facility->id && $member->permits('records.release')
            && $r->patient_decided_at && in_array($r->declined_by, [null, 'hospital'], true);
        abort_unless($asker || $holder, 404);

        return RecordRequestView::of($r) + ['side' => $asker ? 'outgoing' : 'incoming'];
    }

    public function store(Request $request, Facility $facility)
    {
        $this->requirePermission($request, $facility, 'records.request', "You can't request records at this hospital.");
        $data = $request->validate([
            'nhc_id' => ['required', 'string', 'max:24'],
            'holding_facility_id' => ['required', 'string'],
            'record_types' => ['required', 'array', 'min:1'],
            'record_types.*' => ['string', Rule::in(array_keys(config('nhc.record_types')))],
            'access_type' => ['required', Rule::in(['view', 'copy'])],
            'duration_days' => ['nullable', 'integer', 'min:1', 'max:'.config('nhc.record_access.max_days')],
            'reason' => ['required', 'string', 'min:10', 'max:1000'],
        ]);

        $patient = Patient::where('nhc_id', strtoupper($data['nhc_id']))->first() ?? abort(404, 'No NHC record with that ID.');
        $holder = Facility::find($data['holding_facility_id']) ?? abort(404, 'Hospital not found.');

        $r = $this->requests->create($facility, $holder, $patient, $request->user(), $data, $data['reason']);

        return response()->json(RecordRequestView::of($r->load(['patient', 'requester', 'holder', 'requestedBy'])), 201);
    }

    /** The patient is at A's desk: text them what's being asked, with a code. */
    public function consentCode(Request $request, Facility $facility, string $id, OneTimeCodes $codes, Termii $sms)
    {
        $this->requirePermission($request, $facility, 'records.request');
        $r = $this->outgoing($facility, $id);
        abort_unless($r->status === 'awaiting_patient', 409, 'The patient has already decided.');

        $codes->throttle('record_consent', $r->id);
        [$record, $plain] = $codes->issue('record_consent', 'sms', $request->user(), ['request_id' => $r->id]);
        $sms->send($r->patient->phone, "{$r->requester->name} is asking {$r->holder->name} for your health records. "
            ."If you agree, give them this code: {$plain}. If not, don't share it.");

        return ['challenge' => $record->id, 'expires_in' => OneTimeCodes::TTL_MINUTES * 60];
    }

    /** The patient's decision, entered at A's desk with their code. */
    public function consent(Request $request, Facility $facility, string $id, OneTimeCodes $codes)
    {
        $this->requirePermission($request, $facility, 'records.request');
        $data = $request->validate($this->decisionRules() + [
            'challenge' => ['required', 'string'],
            'code' => ['required', 'string', 'max:10'],
            'decision' => ['required', Rule::in(['approve', 'decline'])],
        ]);
        $r = $this->outgoing($facility, $id);

        $code = $codes->verify($data['challenge'], $data['code'], 'record_consent');
        abort_unless($code->subject_id === $request->user()->id && $code->payload['request_id'] === $r->id, 422, 'That code is wrong or has expired.');

        $r = $this->requests->patientDecision($r, $data['decision'] === 'approve', $this->narrowing($data), 'sms');

        return RecordRequestView::of($r->fresh(['patient', 'requester', 'holder', 'requestedBy']));
    }

    public function cancel(Request $request, Facility $facility, string $id)
    {
        $this->requirePermission($request, $facility, 'records.request');

        return RecordRequestView::of($this->requests->cancel($this->outgoing($facility, $id), $request->user()));
    }

    public function release(Request $request, Facility $facility, string $id)
    {
        $this->requirePermission($request, $facility, 'records.release', "You can't release records at this hospital.");
        $data = $request->validate($this->decisionRules());

        $r = $this->requests->hospitalDecision($this->incoming($facility, $id), $request->user(), true, $this->narrowing($data), null);

        return RecordRequestView::of($r->fresh(['patient', 'requester', 'holder', 'requestedBy']));
    }

    public function decline(Request $request, Facility $facility, string $id)
    {
        $this->requirePermission($request, $facility, 'records.release', "You can't release records at this hospital.");
        $data = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:500']]);

        $r = $this->requests->hospitalDecision($this->incoming($facility, $id), $request->user(), false, [], $data['reason']);

        return RecordRequestView::of($r->fresh(['patient', 'requester', 'holder', 'requestedBy']));
    }

    private function decisionRules(): array
    {
        return [
            'record_types' => ['nullable', 'array', 'min:1'],
            'record_types.*' => ['string', Rule::in(array_keys(config('nhc.record_types')))],
            'access_type' => ['nullable', Rule::in(['view', 'copy'])],
            'duration_days' => ['nullable', 'integer', 'min:1', 'max:'.config('nhc.record_access.max_days')],
        ];
    }

    private function narrowing(array $data): array
    {
        return array_intersect_key($data, array_flip(['record_types', 'access_type', 'duration_days']));
    }

    private function outgoing(Facility $facility, string $id): RecordRequest
    {
        return RecordRequest::with(['patient', 'requester', 'holder', 'requestedBy'])
            ->where('requesting_facility_id', $facility->id)->findOrFail($id);
    }

    /** B only ever sees a request after the patient approved it. */
    private function incoming(Facility $facility, string $id): RecordRequest
    {
        return RecordRequest::with(['patient', 'requester', 'holder', 'requestedBy'])
            ->where('holding_facility_id', $facility->id)->whereNotNull('patient_decided_at')->findOrFail($id);
    }
}
