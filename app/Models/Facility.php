<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;
use Stancl\Tenancy\Contracts\TenantWithDatabase;
use Stancl\Tenancy\Database\Concerns\HasDatabase;
use Stancl\Tenancy\Database\Models\Tenant;

/** A hospital. The tenant row is central; its back-office database is its own. */
class Facility extends Tenant implements TenantWithDatabase
{
    use HasDatabase;

    protected $table = 'tenants';

    public static function getCustomColumns(): array
    {
        return [
            'id', 'name', 'type', 'status', 'email', 'phone', 'state', 'lga', 'address', 'latitude', 'longitude', 'postcode',
            'hospify_organisation_id', 'hospify_provider_id', 'created_at', 'updated_at',
        ];
    }

    public function memberships(): HasMany
    {
        return $this->hasMany(FacilityMembership::class, 'facility_id');
    }
}
