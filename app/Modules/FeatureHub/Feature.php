<?php

namespace App\Modules\FeatureHub;

use Illuminate\Database\Eloquent\Model;
use Stancl\Tenancy\Database\Concerns\CentralConnection;

class Feature extends Model
{
    use CentralConnection;

    protected $primaryKey = 'key';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['is_core' => 'boolean', 'default_on' => 'boolean', 'depends_on' => 'array'];
    }
}
