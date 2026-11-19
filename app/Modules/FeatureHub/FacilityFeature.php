<?php

namespace App\Modules\FeatureHub;

use Illuminate\Database\Eloquent\Model;
use Stancl\Tenancy\Database\Concerns\CentralConnection;

class FacilityFeature extends Model
{
    use CentralConnection;

    protected $table = 'facility_features';

    public $incrementing = false;

    protected $primaryKey = null;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['enabled' => 'boolean'];
    }
}
