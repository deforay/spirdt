<?php

declare(strict_types=1);

namespace App\Service;

use App\Models\Facility;
use App\Models\GeoUnit;
use App\Models\TestingSite;
use App\Support\BinaryUuid;
use InvalidArgumentException;

/**
 * The national list: places, facilities, and the benches inside them.
 *
 * ```
 * geo unit (Province) → geo unit (District) → facility → testing site
 * ```
 *
 * All of it belongs to the programme rather than an organisation, so two
 * organisations auditing in the same country reference the same rows. Every
 * query here is scoped by the models; there is no programme parameter to pass
 * or to tamper with.
 *
 * THE HIERARCHY IS NOT TWO LEVELS DEEP. `level` is free text and depth comes
 * from the parent chain, because the tiering is a per-country fact — Zambia
 * has Province → District, Ethiopia has Region → Zone → Woreda. The whole tree
 * is returned flat and assembled by the caller: a national list is a few
 * hundred rows, and a recursive endpoint would be slower and harder to cache
 * than sending all of it once.
 *
 * NOTHING HERE DELETES. Deactivation instead, for two reasons: assessments
 * already reference these rows and an audit trail that loses its subject is
 * not one, and `geo_units.parent_id` cascades on delete, so removing a
 * province would silently take every district and facility under it.
 */
final class RegistryService
{
    /**
     * The whole tree, flat.
     *
     * @return list<array<string,mixed>>
     */
    public function geoUnits(): array
    {
        $query = GeoUnit::query();
        $query->getQuery()->orderBy('level')->orderBy('name');

        $units = [];

        foreach ($query->get() as $unit) {
            $units[] = [
                'id'        => (int) $unit->id,
                'parent_id' => $unit->parent_id === null ? null : (int) $unit->parent_id,
                'level'     => (string) $unit->level,
                'name'      => (string) $unit->name,
                'code'      => $unit->code,
                'is_active' => (bool) $unit->is_active,
            ];
        }

        return $units;
    }

    /**
     * @param  array<string,mixed> $input
     * @return array<string,mixed>
     */
    public function createGeoUnit(array $input): array
    {
        $name = $this->requireText($input, 'name');
        $level = $this->requireText($input, 'level');
        $parentId = $this->optionalInt($input, 'parent_id');

        if ($parentId !== null && GeoUnit::query()->where('geo_units.id', $parentId)->first() === null) {
            throw new InvalidArgumentException('That parent is not in this programme.');
        }

        // A place cannot be its own parent's sibling twice over. Checked on
        // (parent, name) rather than name alone: two districts called Central
        // in different provinces are ordinary, two in the same one are a typo.
        $duplicate = GeoUnit::query()
            ->where('name', $name)
            ->where(
                fn ($query) => $parentId === null
                    ? $query->whereNull('parent_id')
                    : $query->where('parent_id', $parentId),
            )
            ->first();

        if ($duplicate !== null) {
            throw new InvalidArgumentException($name . ' is already there.');
        }

        $unit = new GeoUnit();
        $unit->fill([
            'parent_id' => $parentId,
            'level'     => $level,
            'name'      => $name,
            'code'      => $this->optionalText($input, 'code'),
            'is_active' => 1,
        ]);
        $unit->save();

        return $this->oneGeoUnit((int) $unit->id);
    }

    /**
     * @param  array<string,mixed> $input
     * @return array<string,mixed>
     */
    public function updateGeoUnit(int $id, array $input): array
    {
        $unit = GeoUnit::query()->where('geo_units.id', $id)->first();

        if ($unit === null) {
            throw new InvalidArgumentException('No such place in this programme.');
        }

        $attributes = [];

        if (array_key_exists('name', $input)) {
            $attributes['name'] = $this->requireText($input, 'name');
        }

        if (array_key_exists('level', $input)) {
            $attributes['level'] = $this->requireText($input, 'level');
        }

        if (array_key_exists('code', $input)) {
            $attributes['code'] = $this->optionalText($input, 'code');
        }

        if (array_key_exists('is_active', $input)) {
            $attributes['is_active'] = (bool) $input['is_active'] ? 1 : 0;
        }

        if ($attributes !== []) {
            GeoUnit::query()->where('geo_units.id', $id)->update($attributes);
        }

        return $this->oneGeoUnit($id);
    }

    /**
     * Facilities, optionally under one place.
     *
     * @return list<array<string,mixed>>
     */
    public function facilities(?int $geoUnitId = null): array
    {
        $query = Facility::query();

        if ($geoUnitId !== null) {
            $query->where('geo_unit_id', $geoUnitId);
        }

        $query->getQuery()->orderBy('name');

        $facilities = [];

        foreach ($query->get() as $facility) {
            $facilities[] = [
                'id'            => (string) $facility->id,
                'geo_unit_id'   => $facility->geo_unit_id === null ? null : (int) $facility->geo_unit_id,
                'name'          => (string) $facility->name,
                'code'          => $facility->code,
                'facility_type' => $facility->facility_type,
                'level'         => $facility->level,
                'affiliation'   => $facility->affiliation,
                // 'field' means an assessor created it on the spot and nobody
                // has reconciled it against the registry yet. Worth showing.
                'source'        => (string) $facility->source,
                'is_active'     => (bool) $facility->is_active,
            ];
        }

        return $facilities;
    }

    /**
     * @param  array<string,mixed> $input
     * @return array<string,mixed>
     */
    public function createFacility(array $input): array
    {
        $name = $this->requireText($input, 'name');
        $geoUnitId = $this->optionalInt($input, 'geo_unit_id');

        if ($geoUnitId !== null && GeoUnit::query()->where('geo_units.id', $geoUnitId)->first() === null) {
            throw new InvalidArgumentException('That place is not in this programme.');
        }

        $facility = new Facility();
        $facility->id = BinaryUuid::v7();
        $facility->fill([
            'geo_unit_id'   => $geoUnitId,
            'name'          => $name,
            'code'          => $this->optionalText($input, 'code'),
            'address'       => $this->optionalText($input, 'address'),
            'facility_type' => $this->optionalText($input, 'facility_type'),
            'level'         => $this->optionalText($input, 'level'),
            'affiliation'   => $this->optionalText($input, 'affiliation'),
            // Entered centrally rather than found in the field, and therefore
            // originating from no particular organisation — organization_id on
            // the registry is provenance now, not scope.
            'source'        => 'registry',
            'is_active'     => 1,
        ]);
        $facility->save();

        return $this->oneFacility((string) $facility->id);
    }

    /**
     * @param  array<string,mixed> $input
     * @return array<string,mixed>
     */
    public function updateFacility(string $id, array $input): array
    {
        $facility = Facility::query()->where('facilities.id', BinaryUuid::toBytes($id))->first();

        if ($facility === null) {
            throw new InvalidArgumentException('No such facility in this programme.');
        }

        $attributes = [];

        if (array_key_exists('name', $input)) {
            $attributes['name'] = $this->requireText($input, 'name');
        }

        if (array_key_exists('geo_unit_id', $input)) {
            $geoUnitId = $this->optionalInt($input, 'geo_unit_id');

            if ($geoUnitId !== null && GeoUnit::query()->where('geo_units.id', $geoUnitId)->first() === null) {
                throw new InvalidArgumentException('That place is not in this programme.');
            }

            $attributes['geo_unit_id'] = $geoUnitId;
        }

        foreach (['code', 'address', 'facility_type', 'level', 'affiliation'] as $field) {
            if (array_key_exists($field, $input)) {
                $attributes[$field] = $this->optionalText($input, $field);
            }
        }

        if (array_key_exists('is_active', $input)) {
            $attributes['is_active'] = (bool) $input['is_active'] ? 1 : 0;
        }

        if ($attributes !== []) {
            Facility::query()->where('facilities.id', BinaryUuid::toBytes($id))->update($attributes);
        }

        return $this->oneFacility($id);
    }

    /**
     * Testing sites, optionally within one facility.
     *
     * @return list<array<string,mixed>>
     */
    public function testingSites(?string $facilityId = null): array
    {
        $query = TestingSite::query();

        if ($facilityId !== null) {
            $query->where('facility_id', BinaryUuid::toBytes($facilityId));
        }

        $query->getQuery()->orderBy('name');

        $sites = [];

        foreach ($query->get() as $site) {
            $sites[] = [
                'id'                   => (string) $site->id,
                'facility_id'          => (string) $site->facility_id,
                'name'                 => (string) $site->name,
                'location_description' => $site->location_description,
                'source'               => (string) $site->source,
                'is_active'            => (bool) $site->is_active,
            ];
        }

        return $sites;
    }

    /**
     * @param  array<string,mixed> $input
     * @return array<string,mixed>
     */
    public function createTestingSite(array $input): array
    {
        $name = $this->requireText($input, 'name');
        $facilityId = trim((string) ($input['facility_id'] ?? ''));

        if (!BinaryUuid::isValid($facilityId)) {
            throw new InvalidArgumentException('A facility is required.');
        }

        if (Facility::query()->where('facilities.id', BinaryUuid::toBytes($facilityId))->first() === null) {
            throw new InvalidArgumentException('That facility is not in this programme.');
        }

        $site = new TestingSite();
        $site->id = BinaryUuid::v7();
        $site->fill([
            'facility_id'          => $facilityId,
            'name'                 => $name,
            'location_description' => $this->optionalText($input, 'location_description'),
            'source'               => 'registry',
            'is_active'            => 1,
        ]);
        $site->save();

        return $this->oneTestingSite((string) $site->id);
    }

    /**
     * @param  array<string,mixed> $input
     * @return array<string,mixed>
     */
    public function updateTestingSite(string $id, array $input): array
    {
        $site = TestingSite::query()->where('testing_sites.id', BinaryUuid::toBytes($id))->first();

        if ($site === null) {
            throw new InvalidArgumentException('No such testing site in this programme.');
        }

        $attributes = [];

        if (array_key_exists('name', $input)) {
            $attributes['name'] = $this->requireText($input, 'name');
        }

        if (array_key_exists('location_description', $input)) {
            $attributes['location_description'] = $this->optionalText($input, 'location_description');
        }

        if (array_key_exists('is_active', $input)) {
            $attributes['is_active'] = (bool) $input['is_active'] ? 1 : 0;
        }

        if ($attributes !== []) {
            TestingSite::query()->where('testing_sites.id', BinaryUuid::toBytes($id))->update($attributes);
        }

        return $this->oneTestingSite($id);
    }

    // ─── helpers ───

    /** @return array<string,mixed> */
    private function oneGeoUnit(int $id): array
    {
        foreach ($this->geoUnits() as $unit) {
            if ($unit['id'] === $id) {
                return $unit;
            }
        }

        throw new InvalidArgumentException('No such place in this programme.');
    }

    /** @return array<string,mixed> */
    private function oneFacility(string $id): array
    {
        foreach ($this->facilities() as $facility) {
            if ($facility['id'] === $id) {
                return $facility;
            }
        }

        throw new InvalidArgumentException('No such facility in this programme.');
    }

    /** @return array<string,mixed> */
    private function oneTestingSite(string $id): array
    {
        foreach ($this->testingSites() as $site) {
            if ($site['id'] === $id) {
                return $site;
            }
        }

        throw new InvalidArgumentException('No such testing site in this programme.');
    }

    /** @param array<string,mixed> $input */
    private function requireText(array $input, string $key): string
    {
        $value = trim((string) ($input[$key] ?? ''));

        if ($value === '') {
            throw new InvalidArgumentException(ucfirst(str_replace('_', ' ', $key)) . ' is required.');
        }

        return $value;
    }

    /** @param array<string,mixed> $input */
    private function optionalText(array $input, string $key): ?string
    {
        $value = trim((string) ($input[$key] ?? ''));

        return $value === '' ? null : $value;
    }

    /** @param array<string,mixed> $input */
    private function optionalInt(array $input, string $key): ?int
    {
        $value = $input[$key] ?? null;

        return is_numeric($value) ? (int) $value : null;
    }
}
