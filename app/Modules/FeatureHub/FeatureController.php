<?php

namespace App\Modules\FeatureHub;

use App\Http\Controllers\Controller;
use App\Models\Facility;
use App\Models\FacilityMembership;
use App\Support\FacilityTypes;
use Illuminate\Http\Request;

/** The Feature hub: every feature, grouped, with this hospital's state. Admins switch them. */
class FeatureController extends Controller
{
    public function index(Request $request, Facility $facility, Features $features)
    {
        $member = FacilityMembership::activeFor($request->user(), $facility);
        abort_unless($member || $request->user()->is_super_admin, 403, "You don't work at this hospital.");

        $states = $features->states($facility);
        $canManage = $request->user()->is_super_admin || $member?->permits('features.manage');

        return [
            'can_manage' => (bool) $canManage,
            'facility' => FacilityTypes::describe($facility),
            'features' => $features->available($facility)->map(fn (Feature $f) => [
                'key' => $f->key,
                'name' => $f->name,
                'description' => $f->description,
                'category' => $f->category,
                'core' => $f->is_core,
                'status' => $f->status,
                'depends_on' => $f->depends_on ?? [],
                'enabled' => $states[$f->key] ?? false,
            ])->values(),
        ];
    }

    public function update(Request $request, Facility $facility, string $key, Features $features)
    {
        $user = $request->user();
        abort_unless($user->is_super_admin || FacilityMembership::activeFor($user, $facility)?->permits('features.manage'), 403,
            "Only this hospital's admins can switch features.");

        $data = $request->validate(['enabled' => ['required', 'boolean']]);
        $features->set($facility, $key, $data['enabled'], $user);

        return ['key' => $key, 'enabled' => $features->enabled($facility, $key)];
    }
}
