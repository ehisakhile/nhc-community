<?php

namespace App\Models\Concerns;

/**
 * For central tables that hold a hospital's rows. A forgotten filter returns
 * nothing rather than everything. Only the access service may remove the scope.
 */
trait BelongsToFacility
{
    public static function bootBelongsToFacility(): void
    {
        static::addGlobalScope(new FacilityScope);
    }
}
