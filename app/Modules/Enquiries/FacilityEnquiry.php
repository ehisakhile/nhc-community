<?php

namespace App\Modules\Enquiries;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Stancl\Tenancy\Database\Concerns\CentralConnection;

class FacilityEnquiry extends Model
{
    use CentralConnection, HasUlids;

    protected $guarded = ['id', 'status'];
}
