<?php

namespace App\Modules\FeatureHub\Console;

use App\Modules\FeatureHub\Feature;
use App\Modules\ModuleRegistry;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/** Writes what the modules declare into the `features` table. Hospitals' choices are kept. */
#[Signature('nhc:features:sync')]
#[Description('Update the feature catalogue from the modules (runs on every deploy)')]
class SyncFeatures extends Command
{
    public function handle(ModuleRegistry $modules): int
    {
        $declared = $modules->features();

        foreach ($declared as $key => $f) {
            Feature::updateOrCreate(['key' => $key], [
                'module' => $f['module'],
                'name' => $f['name'],
                'description' => $f['description'],
                'category' => $f['category'],
                'is_core' => $f['core'] ?? false,
                'default_on' => $f['default_on'] ?? ($f['core'] ?? false),
                'status' => $f['status'] ?? 'live',
                'depends_on' => $f['depends_on'] ?? [],
                'sort' => $f['sort'],
            ]);
        }

        // A feature no module declares any more is gone (and so are the choices for it).
        Feature::whereNotIn('key', array_keys($declared))->delete();

        $this->info(count($declared).' features in the catalogue.');

        return self::SUCCESS;
    }
}
