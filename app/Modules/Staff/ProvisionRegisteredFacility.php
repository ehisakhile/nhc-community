<?php

namespace App\Modules\Staff;

use App\Models\Facility;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use RuntimeException;
use Stancl\Tenancy\Jobs\CreateDatabase;

/** Tenant DDL runs after central registration commits and is safe to resume. */
class ProvisionRegisteredFacility
{
    public function ensureReady(Facility $facility): void
    {
        if ($facility->registration_provisioned) {
            return;
        }
        Cache::lock('facility-registration:'.$facility->id, 600)->block(5, function () use ($facility): void {
            $facility->refresh();
            if ($facility->registration_provisioned) {
                return;
            }
            $facility->setInternal('create_database', true);
            $facility->database()->makeCredentials();
            $database = $facility->database();
            if (! $database->manager()->databaseExists($database->getName())) {
                app()->call([new CreateDatabase($facility), 'handle']);
            }
            if (! $database->manager()->databaseExists($database->getName())) {
                throw new RuntimeException('Tenant database creation did not complete.');
            }
            $exit = Artisan::call('tenants:migrate', ['--tenants' => [$facility->id], '--force' => true]);
            if ($exit !== 0) {
                throw new RuntimeException('Tenant migrations did not complete.');
            }
            $facility->update(['registration_provisioned' => true]);
        });
    }
}
