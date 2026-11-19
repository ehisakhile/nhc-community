<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Stancl\Tenancy\Database\Concerns\CentralConnection;

class PatientIdentityDocument extends Model
{
    use CentralConnection, HasUlids;

    protected $fillable = [
        'patient_id', 'type', 'number_hash', 'number', 'last_four', 'facility_id', 'checked_by', 'checked_at',
    ];

    protected $hidden = ['number', 'number_hash'];

    protected function casts(): array
    {
        return ['number' => 'encrypted', 'checked_at' => 'datetime'];
    }

    /** Lets us find a duplicate without storing the number in a searchable form. */
    public static function hashNumber(string $type, string $number): string
    {
        return hash_hmac('sha256', $type.'|'.self::clean($number), config('app.key'));
    }

    public static function clean(string $number): string
    {
        return strtoupper(preg_replace('/[\s-]/', '', $number));
    }
}
