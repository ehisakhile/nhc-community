<?php

namespace App\Models\Concerns;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/** Limits a query to the current hospital. With no hospital set, it returns nothing. */
class FacilityScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $facilityId = tenant('id');

        if ($facilityId === null) {
            $builder->whereRaw('1 = 0');

            return;
        }

        $builder->where($model->qualifyColumn('facility_id'), $facilityId);
    }
}
