<?php

namespace App\Modules\FeatureHub;

use App\Modules\FeatureHub\Console\SyncFeatures;
use App\Modules\Module;

/**
 * The Feature hub, and the features planned but not built yet (shown as
 * "coming soon"; each moves to its own module when it's built).
 */
class FeatureHubModule extends Module
{
    public function key(): string
    {
        return 'feature_hub';
    }

    public function commands(): array
    {
        return [SyncFeatures::class];
    }

    public function features(): array
    {
        $soon = fn (string $key, string $name, string $category, string $description, array $depends = []) => [
            'key' => $key, 'name' => $name, 'category' => $category, 'description' => $description,
            'status' => 'coming_soon', 'depends_on' => $depends,
        ];

        return [
            $soon('wards', 'Wards and beds', 'Clinical', 'Admissions, beds and nursing rounds.'),
            $soon('billing', 'Billing', 'Finance', 'Bills, payments and receipts.'),
        ];
    }
}
