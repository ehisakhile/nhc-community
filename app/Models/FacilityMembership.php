<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Stancl\Tenancy\Database\Concerns\CentralConnection;

class FacilityMembership extends Model
{
    use CentralConnection, HasUlids;

    protected $fillable = ['user_id', 'facility_id', 'role', 'status', 'licence_number', 'licence_checked_at'];

    protected function casts(): array
    {
        return ['licence_checked_at' => 'datetime'];
    }

    public function permits(string $permission): bool
    {
        return $this->status === 'active'
            && in_array($permission, config("nhc.roles.{$this->role}.permissions", []), true);
    }

    /** The staff member's active job at this hospital, if any. */
    public static function activeFor(User $user, Facility $facility): ?self
    {
        return self::where(['user_id' => $user->id, 'facility_id' => $facility->id, 'status' => 'active'])->first();
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function facility(): BelongsTo
    {
        return $this->belongsTo(Facility::class, 'facility_id');
    }
}
