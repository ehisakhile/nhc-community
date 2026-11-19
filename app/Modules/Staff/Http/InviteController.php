<?php

namespace App\Modules\Staff\Http;

use App\Http\Controllers\Controller;
use App\Mail\StaffInvite;
use App\Models\AuditLog;
use App\Models\Facility;
use App\Models\FacilityMembership;
use App\Models\User;
use App\Support\FacilityTypes;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/** A hospital admin invites staff by email. Nobody chooses a password. */
class InviteController extends Controller
{
    public function store(Request $request, Facility $facility)
    {
        $admin = $request->user();
        $isAdmin = $admin->is_super_admin || FacilityMembership::activeFor($admin, $facility)?->permits('staff.invite');
        abort_unless($isAdmin, 403, 'Only this hospital\'s admins can invite staff.');

        abort_if($facility->type === 'solo', 422, 'A solo practice has one owner. Use a clinic workspace to add staff.');

        $data = $request->validate([
            'email' => ['required', 'email:rfc', 'max:255'],
            'name' => ['required', 'string', 'max:150'],
            // Only the roles this facility's type has: a pharmacy has no midwives.
            'role' => ['required', Rule::in(FacilityTypes::roles($facility))],
            'licence_number' => ['nullable', 'string', 'max:50'],
        ]);

        $user = User::firstOrCreate(['email' => Str::lower(trim($data['email']))], ['name' => $data['name']]);

        $membership = FacilityMembership::firstOrNew(['user_id' => $user->id, 'facility_id' => $facility->id]);
        abort_if($membership->exists && $membership->status === 'active', 409, 'They already work at this hospital on NHC.');
        $membership->fill(['role' => $data['role'], 'status' => 'invited', 'licence_number' => $data['licence_number'] ?? null])->save();

        Mail::to($user->email)->send(new StaffInvite($user->name, $facility->name, config("nhc.roles.{$data['role']}.label")));

        AuditLog::create([
            'actor_type' => 'staff', 'actor_id' => $admin->id, 'facility_id' => $facility->id,
            'action' => 'staff.invited', 'target_type' => 'user', 'target_id' => $user->id,
            'ip_address' => $request->ip(), 'occurred_at' => now(),
        ]);

        return response()->json(['email' => $user->email, 'role' => $membership->role, 'status' => 'invited'], 201);
    }
}
