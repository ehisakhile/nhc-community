<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Stancl\Tenancy\Database\Concerns\CentralConnection;

class PatientPhone extends Model
{
    use CentralConnection, HasUlids;

    protected $fillable = ['phone', 'verified_at', 'replaced_at'];

    protected function casts(): array
    {
        return ['verified_at' => 'datetime', 'replaced_at' => 'datetime'];
    }
}
