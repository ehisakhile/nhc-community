<?php

namespace App\Modules\Staff\Http;

use App\Http\Controllers\Controller;
use App\Modules\FeatureHub\Features;
use App\Support\FacilityTypes;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/**
 * Who the staff member is and their job at each hospital. The app opens the
 * dashboard for the hospital they pick and shows only what the role permits.
 */
class ProfileController extends Controller
{
    public function show(Request $request, Features $features)
    {
        $user = $request->user();

        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'hospitals' => $user->memberships()->where('status', 'active')->with('facility')->get()
                ->map(fn ($m) => [
                    'facility' => [...$m->facility->only(['id', 'name', 'state', 'lga', 'status', 'address', 'postcode', 'phone']), ...FacilityTypes::describe($m->facility), 'logo_url' => $m->facility->logo_path ? Storage::disk('public')->url($m->facility->logo_path) : null],
                    'role' => $m->role,
                    'role_label' => config("nhc.roles.{$m->role}.label"),
                    'dashboard' => config("nhc.roles.{$m->role}.dashboard"),
                    'permissions' => config("nhc.roles.{$m->role}.permissions", []),
                    // What this hospital has switched on (Feature hub).
                    'features' => $features->enabledKeys($m->facility),
                    // Everything this type is offered, live or coming, for the sidebar.
                    'offered' => $features->available($m->facility)->pluck('key')->values(),
                    // Roles this facility's type can invite, for the invite form.
                    'staff_roles' => collect(FacilityTypes::roles($m->facility))->map(fn ($r) => ['role' => $r, 'label' => config("nhc.roles.{$r}.label")])->values(),
                ]),
        ];
    }
}
