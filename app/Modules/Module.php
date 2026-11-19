<?php

namespace App\Modules;

use ReflectionClass;

/**
 * A self-contained part of NHC: its own routes, controllers and services, and
 * the features it gives hospitals. Modules are listed in config/modules.php.
 *
 * Features are declared here and synced into the `features` table on every
 * deploy (php artisan nhc:features:sync). The Feature hub reads that table, and
 * each hospital switches non-core features on or off.
 */
abstract class Module
{
    abstract public function key(): string;

    /**
     * @return list<array{key: string, name: string, description: string, category: string, core?: bool, default_on?: bool, status?: 'live'|'coming_soon', depends_on?: list<string>}>
     */
    public function features(): array
    {
        return [];
    }

    /** The module's routes.php, if it has one. Loaded under /api. */
    public function routes(): ?string
    {
        $file = dirname((new ReflectionClass($this))->getFileName()).'/routes.php';

        return is_file($file) ? $file : null;
    }

    /** @return list<class-string> Artisan commands the module brings. */
    public function commands(): array
    {
        return [];
    }
}
