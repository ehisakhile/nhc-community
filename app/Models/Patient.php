<?php

namespace App\Models;

use App\Exceptions\ImmutableRecord;
use Illuminate\Auth\Authenticatable;
use Illuminate\Contracts\Auth\Authenticatable as AuthenticatableContract;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Laravel\Sanctum\HasApiTokens;
use Stancl\Tenancy\Database\Concerns\CentralConnection;

/** Signs in through the Hospify Citizen app. See HospifySignInController. */
class Patient extends Model implements AuthenticatableContract
{
    use Authenticatable, CentralConnection, HasApiTokens, HasUlids, SoftDeletes;

    protected $hidden = ['nhc_base', 'nhc_suffix', 'hospify_ref', 'remember_token'];

    protected $fillable = [
        'first_name', 'last_name', 'other_names', 'date_of_birth', 'gender',
    ];

    protected function casts(): array
    {
        return [
            'date_of_birth' => 'date',
            'phone_verified_at' => 'datetime',
            'email_verified_at' => 'datetime',
            'hospify_linked_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        // An ID is for life, so its row can never be rewritten or removed.
        static::updating(function (Patient $patient) {
            if ($patient->isDirty(['nhc_id', 'nhc_base', 'nhc_suffix'])) {
                throw new ImmutableRecord('An NHC ID can never change.');
            }
        });

        static::forceDeleting(function () {
            throw new ImmutableRecord('A patient row can never be removed, or its ID could be reused.');
        });
    }

    public function guardian(): BelongsTo
    {
        return $this->belongsTo(self::class, 'guardian_id');
    }

    public function dependants(): HasMany
    {
        return $this->hasMany(self::class, 'guardian_id');
    }

    public function phones(): HasMany
    {
        return $this->hasMany(PatientPhone::class);
    }

    /** No passwords: patients sign in with email codes. */
    public function getAuthPassword(): string
    {
        return '';
    }

    public function getRememberTokenName(): string
    {
        return '';
    }

    public function identityDocuments(): HasMany
    {
        return $this->hasMany(PatientIdentityDocument::class);
    }

    public function facilityLinks(): HasMany
    {
        return $this->hasMany(PatientFacilityLink::class);
    }

    /** Where an OTP goes: the phone on file, never the digits in the ID. */
    public function otpPhone(): ?string
    {
        return $this->phone ?? $this->guardian?->otpPhone();
    }
}
