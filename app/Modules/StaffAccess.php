<?php

namespace App\Modules;

use App\Models\Facility;
use App\Models\FacilityMembership;
use Illuminate\Http\Request;

/** For controllers acting at one hospital: the signed-in staff member must hold a permission there. */
trait StaffAccess
{
    protected function requirePermission(Request $request, Facility $facility, string $permission, string $message = "You can't do that at this hospital."): FacilityMembership
    {
        abort_unless($facility->status === 'active', 422, "This hospital isn't live on NHC yet.");
        $member = FacilityMembership::activeFor($request->user(), $facility);
        abort_unless($member?->permits($permission), 403, $message);

        return $member;
    }
}
