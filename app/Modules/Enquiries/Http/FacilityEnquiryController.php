<?php

namespace App\Modules\Enquiries\Http;

use App\Http\Controllers\Controller;
use App\Modules\Enquiries\FacilityEnquiry;
use App\Modules\Enquiries\NewFacilityEnquiry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\Rule;
use Throwable;

class FacilityEnquiryController extends Controller
{
    public const TYPES = [
        'General Hospital', 'Specialized Hospital', 'Community Health Center', 'Clinic', 'Pharmacy',
        'Laboratory', 'Diagnostic Center', 'Dental Clinic', 'Optometry Clinic', 'Maternity Home',
    ];

    public const DEPARTMENTS = ['1-5', '6-10', '11-20', '21-50', '50+'];

    /** A hospital asks to join. Saved first, then the team is told by email. */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'facility_name' => ['required', 'string', 'min:2', 'max:200'],
            'facility_type' => ['required', Rule::in(self::TYPES)],
            'contact_name' => ['required', 'string', 'min:2', 'max:200'],
            'email' => ['required', 'email', 'max:200'],
            'phone' => ['required', 'string', 'min:7', 'max:20'],
            'city' => ['required', 'string', 'min:2', 'max:100'],
            'state' => ['required', 'string', 'min:2', 'max:100'],
            'department_count' => ['nullable', Rule::in(self::DEPARTMENTS)],
        ]);

        $enquiry = FacilityEnquiry::create($data);

        if ($to = config('nhc.enquiries_to')) {
            try {
                Mail::to($to)->send(new NewFacilityEnquiry($enquiry));
            } catch (Throwable $e) {
                report($e); // it's saved; the team can still find it
            }
        }

        return response()->json(['id' => $enquiry->id, 'message' => 'Thanks. We\'ll be in touch.'], 201);
    }
}
