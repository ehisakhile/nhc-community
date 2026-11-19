<?php

namespace App\Modules\ClinicalRecords;

use Carbon\CarbonInterface;

/** What one reader may see: per hospital, which record types, and up to when. Built by ClinicalAccess. */
class Reach
{
    /** @var array<string, array{types: list<string>, before: ?CarbonInterface, via: list<string>, until: ?CarbonInterface}> */
    private array $facilities = [];

    /** @var array{types: list<string>, via: list<string>, until: ?CarbonInterface}|null */
    private ?array $everywhere = null;

    public function allow(string $facilityId, array $types, string $via, ?CarbonInterface $before = null, ?CarbonInterface $until = null): self
    {
        if (! $types) {
            return $this;
        }

        $current = $this->facilities[$facilityId] ?? null;
        $this->facilities[$facilityId] = [
            'types' => array_values(array_unique([...$current['types'] ?? [], ...$types])),
            // Any grant without a cut-off wins; otherwise the latest cut-off.
            'before' => $current === null ? $before : ($current['before'] === null || $before === null ? null : max($current['before'], $before)),
            'via' => [...$current['via'] ?? [], $via],
            'until' => $current === null ? $until : ($current['until'] === null || $until === null ? null : max($current['until'], $until)),
        ];

        return $this;
    }

    public function allowEverywhere(array $types, string $via, ?CarbonInterface $until = null): self
    {
        $this->everywhere = [
            'types' => array_values(array_unique([...$this->everywhere['types'] ?? [], ...$types])),
            'via' => [...$this->everywhere['via'] ?? [], $via],
            'until' => $until,
        ];

        return $this;
    }

    public function everywhere(string $type): bool
    {
        return in_array($type, $this->everywhere['types'] ?? [], true);
    }

    /** @return array<string, ?CarbonInterface> hospital ID => cut-off, for hospitals granting this type. */
    public function facilitiesFor(string $type): array
    {
        $out = [];
        foreach ($this->facilities as $id => $f) {
            if (in_array($type, $f['types'], true)) {
                $out[$id] = $f['before'];
            }
        }

        return $out;
    }

    public function can(string $type): bool
    {
        return $this->everywhere($type) || $this->facilitiesFor($type) !== [];
    }

    public function isEmpty(): bool
    {
        return $this->everywhere === null && $this->facilities === [];
    }

    /** For the response: what the reader is seeing, and why. */
    public function sources(): array
    {
        $out = [];
        foreach ($this->facilities as $id => $f) {
            $out[] = ['hospital_id' => $id, 'record_types' => $f['types'], 'via' => $f['via'], 'up_to' => $f['before']?->toIso8601String(), 'until' => $f['until']?->toIso8601String()];
        }
        if ($this->everywhere) {
            $out[] = ['hospital_id' => '*', 'record_types' => $this->everywhere['types'], 'via' => $this->everywhere['via'], 'up_to' => null, 'until' => $this->everywhere['until']?->toIso8601String()];
        }

        return $out;
    }

    /** For the audit log. */
    public function describe(): string
    {
        $vias = [...array_merge(...array_column($this->facilities, 'via') ?: [[]]), ...$this->everywhere['via'] ?? []];

        return implode(', ', array_unique($vias));
    }
}
