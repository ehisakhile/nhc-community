<?php

namespace App\Modules\FeatureHub;

use App\Models\AuditLog;
use App\Models\Facility;
use App\Models\User;
use App\Support\FacilityTypes;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Which features a facility has. Core features are always on. The facility's
 * type decides which others it's offered and which start on (config
 * nhc.facility_types); features it isn't offered are off and hidden.
 * Coming-soon ones are always off; the rest follow the facility's choice.
 */
class Features
{
    /** @var array<string, array<string, bool>> per request */
    private array $cache = [];

    public function catalogue(): Collection
    {
        return Feature::orderBy('sort')->get();
    }

    /** @return array<string, bool> feature key => on, for one hospital */
    public function states(Facility $facility): array
    {
        if (isset($this->cache[$facility->id])) {
            return $this->cache[$facility->id];
        }

        $choices = FacilityFeature::where('facility_id', $facility->id)->pluck('enabled', 'feature_key');
        $states = [];
        foreach ($this->catalogue() as $f) {
            $offered = FacilityTypes::offers($facility, $f->key);
            $states[$f->key] = match (true) {
                $f->is_core => true,
                $offered === null, $f->status !== 'live' => false,
                default => (bool) ($choices[$f->key] ?? $offered),
            };
        }

        return $this->cache[$facility->id] = $states;
    }

    /** The features this facility's type is offered, core ones included. */
    public function available(Facility $facility): Collection
    {
        return $this->catalogue()->filter(fn (Feature $f) => $f->is_core || FacilityTypes::offers($facility, $f->key) !== null)->values();
    }

    public function enabled(Facility $facility, string $key): bool
    {
        return $this->states($facility)[$key] ?? false;
    }

    /** @return list<string> */
    public function enabledKeys(Facility $facility): array
    {
        return array_keys(array_filter($this->states($facility)));
    }

    public function set(Facility $facility, string $key, bool $on, User $by): void
    {
        $feature = Feature::find($key) ?? throw new HttpException(404, 'No such feature.');
        $states = $this->states($facility);

        if ($feature->is_core) {
            throw new HttpException(422, "{$feature->name} is part of NHC and can't be switched off.");
        }
        if (! $feature->is_core && FacilityTypes::offers($facility, $key) === null) {
            $type = FacilityTypes::spec($facility)['label'];
            throw new HttpException(422, "{$feature->name} isn't offered for a {$type}.");
        }
        if ($feature->status !== 'live') {
            throw new HttpException(422, "{$feature->name} isn't available yet.");
        }
        if ($on) {
            $missing = array_filter($feature->depends_on ?? [], fn ($d) => ! ($states[$d] ?? false));
            if ($missing) {
                $names = Feature::whereIn('key', $missing)->pluck('name')->join(', ');
                throw new HttpException(422, "Switch on {$names} first.");
            }
        } else {
            $dependants = $this->catalogue()->filter(fn ($f) => in_array($key, $f->depends_on ?? [], true) && ($states[$f->key] ?? false));
            if ($dependants->isNotEmpty()) {
                throw new HttpException(422, 'Switch off '.$dependants->pluck('name')->join(', ').' first: it needs this.');
            }
        }

        DB::connection((new FacilityFeature)->getConnectionName())->transaction(function () use ($facility, $key, $on, $by) {
            FacilityFeature::updateOrCreate(
                ['facility_id' => $facility->id, 'feature_key' => $key],
                ['enabled' => $on, 'changed_by' => $by->id],
            );
            AuditLog::create([
                'actor_type' => 'staff', 'actor_id' => $by->id, 'facility_id' => $facility->id,
                'action' => $on ? 'feature.enabled' : 'feature.disabled', 'target_type' => 'feature', 'target_id' => $key,
                'occurred_at' => now(),
            ]);
        });

        unset($this->cache[$facility->id]);
    }
}
