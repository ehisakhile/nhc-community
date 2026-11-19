<?php

namespace App\Support;

use App\Models\Facility;

/**
 * What a facility's type gives it: its label, the roles it can have, the
 * features it's offered and which start on, and what it calls the people it
 * serves. Defined in config/nhc.php (facility_types). A facility with no type,
 * or an unknown one, is treated as a hospital.
 */
class FacilityTypes
{
    public const DEFAULT = 'hospital';

    /** @return array<string, string> type => label */
    public static function all(): array
    {
        return array_map(fn ($t) => $t['label'], config('nhc.facility_types'));
    }

    public static function key(Facility|string|null $type): string
    {
        $type = $type instanceof Facility ? $type->type : $type;

        return isset(config('nhc.facility_types')[$type ?? '']) ? $type : self::DEFAULT;
    }

    public static function spec(Facility|string|null $type): array
    {
        return config('nhc.facility_types.'.self::key($type));
    }

    /** @return list<string> */
    public static function roles(Facility|string|null $type): array
    {
        return self::spec($type)['roles'];
    }

    /** Null when the type isn't offered the feature; otherwise whether it starts on. */
    public static function offers(Facility|string|null $type, string $feature): ?bool
    {
        return self::spec($type)['features'][$feature] ?? null;
    }

    public static function describe(Facility $facility): array
    {
        $spec = self::spec($facility);

        return ['type' => self::key($facility), 'type_label' => $spec['label'], 'people_label' => $spec['people']];
    }
}
