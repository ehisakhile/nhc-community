<?php

namespace App\Modules\FeatureHub;

use App\Models\Facility;
use Closure;
use Illuminate\Http\Request;

/** Route middleware `feature:<key>`: the {facility} in the URL must have it switched on. */
class RequireFeature
{
    public function __construct(private Features $features) {}

    public function handle(Request $request, Closure $next, string $key)
    {
        $facility = $request->route('facility');
        $facility = $facility instanceof Facility ? $facility : Facility::find($facility);

        abort_unless($facility && $this->features->enabled($facility, $key), 403, 'This feature is switched off at this hospital.');

        return $next($request);
    }
}
