<?php

namespace App\Modules;

class ModuleRegistry
{
    /** @var list<Module> */
    private array $modules;

    /** @param list<class-string<Module>> $classes */
    public function __construct(array $classes)
    {
        $this->modules = array_map(fn (string $c) => new $c, $classes);
    }

    /** @return list<Module> */
    public function all(): array
    {
        return $this->modules;
    }

    /** Every feature every module declares, keyed by feature key. */
    public function features(): array
    {
        $out = [];
        foreach ($this->modules as $sort => $module) {
            foreach ($module->features() as $i => $f) {
                $out[$f['key']] = $f + ['module' => $module->key(), 'sort' => $sort * 100 + $i];
            }
        }

        return $out;
    }
}
