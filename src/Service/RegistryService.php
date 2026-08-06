<?php

declare(strict_types=1);

namespace App\Service;

use App\Models\Facility;
use App\Models\GeoUnit;
use App\Models\Template;
use App\Models\TestingSite;
use App\Support\BinaryUuid;
use App\Tenancy\TenantContext;
use Illuminate\Database\Capsule\Manager as Capsule;
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

        $this->requireNameFreeAmongSiblings($name, $parentId, null);

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
            $name = $this->requireText($input, 'name');

            // The same rule creating one applies, or whether two districts may
            // share a name under one parent depends on which screen it was
            // typed on. Itself excluded, so saving a form unchanged is not a
            // collision with the row being saved.
            $this->requireNameFreeAmongSiblings($name, $unit->parent_id === null ? null : (int) $unit->parent_id, $id);

            $attributes['name'] = $name;
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
     * How many rows a page holds.
     *
     * A national registry runs to thousands of facilities, so nothing here
     * returns "all of them" — the previous version did, and it worked only
     * because the demo had four.
     */
    public const PAGE_SIZE = 50;

    /**
     * Every place under this one, including itself.
     *
     * Filtering facilities on an exact geo_unit_id was wrong: facilities hang
     * off districts, so choosing a province matched nothing at all. Somebody
     * asking for Copperbelt means every facility in it, however many levels
     * down the tree they sit.
     *
     * Walked in memory from the flat tree rather than with a recursive query.
     * The tree is a few hundred rows and is already loaded for the picker;
     * a recursive CTE per keystroke would be worse in every way.
     *
     * Public because reporting filters on a place the same way, and for the
     * same reason — a province has to mean everything under it there too.
     *
     * @return list<int>
     */
    public function subtree(int $geoUnitId): array
    {
        $children = [];

        foreach ($this->geoUnits() as $unit) {
            $children[$unit['parent_id']][] = $unit['id'];
        }

        $found = [];
        $queue = [$geoUnitId];

        while ($queue !== []) {
            $current = array_pop($queue);
            $found[] = $current;

            foreach ($children[$current] ?? [] as $child) {
                $queue[] = $child;
            }
        }

        return $found;
    }

    /**
     * No two places under one parent may share a name.
     *
     * Checked on (parent, name) rather than name alone: two districts called
     * Central in different provinces are ordinary, two in the same one are a
     * typo. Enforced on create AND on rename, so the rule does not depend on
     * which screen the name arrived from.
     *
     * @param  int|null                 $exceptId the row being edited, so saving it unchanged is not a collision with itself
     * @throws InvalidArgumentException
     */
    private function requireNameFreeAmongSiblings(string $name, ?int $parentId, ?int $exceptId): void
    {
        $query = GeoUnit::query()
            ->where('name', $name)
            ->where(
                fn ($inner) => $parentId === null
                    ? $inner->whereNull('parent_id')
                    : $inner->where('parent_id', $parentId),
            );

        if ($exceptId !== null) {
            $query->where('geo_units.id', '!=', $exceptId);
        }

        if ($query->first() !== null) {
            throw new InvalidArgumentException($name . ' is already there.');
        }
    }

    /**
     * Facilities, narrowed by place or by name.
     *
     * Paginated, and the total comes back with the page: "50 of 1,240" is the
     * difference between a list somebody trusts and one they scroll to the
     * bottom of hoping it ended.
     *
     * @return array{rows: list<array<string,mixed>>, total: int, page: int, per_page: int}
     */
    public function facilities(
        ?int $geoUnitId = null,
        ?string $search = null,
        int $page = 1,
        int $perPage = self::PAGE_SIZE,
        ?string $id = null,
    ): array {
        $perPage = max(1, min($perPage, 200));
        $page = max(1, $page);

        $query = Facility::query();

        if ($id !== null) {
            $query->where('facilities.id', BinaryUuid::toBytes($id));
        }

        if ($geoUnitId !== null) {
            $query->whereIn('geo_unit_id', $this->subtree($geoUnitId));
        }

        $term = $search === null ? '' : trim($search);

        if ($term !== '') {
            // Escaped, because a name legitimately containing % or _ would
            // otherwise widen the search rather than narrow it.
            $escaped = addcslashes($term, '%_\\');

            $query->where(function ($inner) use ($escaped): void {
                $inner->where('name', 'like', '%' . $escaped . '%')
                    ->orWhere('code', 'like', $escaped . '%');
            });
        }

        $total = (int) $query->count();

        $query->getQuery()->orderBy('name')->forPage($page, $perPage);

        $places = $this->placePaths();
        $rows = [];

        foreach ($query->get() as $facility) {
            $geoUnitId = $facility->geo_unit_id === null ? null : (int) $facility->geo_unit_id;

            $rows[] = [
                'id'            => (string) $facility->id,
                'geo_unit_id'   => $geoUnitId,
                // The whole point of searching across places: a row has to say
                // where it is, or two clinics with the same name are the same
                // row to whoever is reading.
                'place'         => $geoUnitId === null ? null : ($places[$geoUnitId] ?? null),
                'name'          => (string) $facility->name,
                'code'          => $facility->code,
                'facility_type' => $facility->facility_type,
                'level'         => $facility->level,
                'affiliation'   => $facility->affiliation,
                'address'       => $facility->address,
                'contact_name'  => $facility->contact_name,
                'contact_phone' => $facility->contact_phone,
                'contact_email' => $facility->contact_email,
                'latitude'      => $facility->latitude === null ? null : (float) $facility->latitude,
                'longitude'     => $facility->longitude === null ? null : (float) $facility->longitude,
                // 'field' means an assessor created it on the spot and nobody
                // has reconciled it against the registry yet. Worth showing.
                'source'        => (string) $facility->source,
                'is_active'     => (bool) $facility->is_active,
            ];
        }

        return ['rows' => $rows, 'total' => $total, 'page' => $page, 'per_page' => $perPage];
    }

    /**
     * Every place by id, as a readable path — "Copperbelt › Kitwe".
     *
     * @return array<int,string>
     */
    public function placePaths(): array
    {
        $byId = [];

        foreach ($this->geoUnits() as $unit) {
            $byId[$unit['id']] = $unit;
        }

        $paths = [];

        foreach ($byId as $id => $unit) {
            $parts = [];
            $current = $unit;
            $guard = 0;

            // Bounded, because a cycle in the tree would otherwise hang the
            // request rather than producing a wrong label.
            while ($current !== null && $guard++ < 20) {
                array_unshift($parts, $current['name']);
                $parentId = $current['parent_id'];
                $current = $parentId === null ? null : ($byId[$parentId] ?? null);
            }

            $paths[$id] = implode(' › ', $parts);
        }

        return $paths;
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
            'contact_name'  => $this->optionalText($input, 'contact_name'),
            'contact_phone' => $this->optionalText($input, 'contact_phone'),
            'contact_email' => $this->optionalEmail($input, 'contact_email'),
            'latitude'      => $this->optionalCoordinate($input, 'latitude', 90),
            'longitude'     => $this->optionalCoordinate($input, 'longitude', 180),
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

        foreach ([
            'code', 'address', 'facility_type', 'level', 'affiliation',
            'contact_name', 'contact_phone',
        ] as $field) {
            if (array_key_exists($field, $input)) {
                $attributes[$field] = $this->optionalText($input, $field);
            }
        }

        if (array_key_exists('contact_email', $input)) {
            $attributes['contact_email'] = $this->optionalEmail($input, 'contact_email');
        }

        foreach (['latitude' => 90, 'longitude' => 180] as $field => $limit) {
            if (array_key_exists($field, $input)) {
                $attributes[$field] = $this->optionalCoordinate($input, $field, $limit);
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
     * Fold one facility into another, because the same place was entered twice.
     *
     * It happens for a reason the design invites: an assessor arriving at a
     * site that is not in the registry creates it on the spot, and "Kitwe
     * Central Hosp." and "Kitwe Central Hospital" are then two rows for one
     * building. That trade was made deliberately — refusing to let an assessor
     * work would have been worse — and this is the other half of it.
     *
     * NOTHING IS DELETED. The loser keeps its row, gains `merged_into_id`, and
     * is deactivated. Assessments already reference it, and an audit trail that
     * loses the thing it audited is not one — "which facility was this visit
     * against?" has to keep resolving, even to a name nobody uses any more.
     *
     * ITS TESTING SITES MOVE. They are what assessments actually point at, so
     * leaving them behind on a deactivated facility would strand every visit
     * ever made through them.
     *
     * Fields are filled, never overwritten: the winner keeps everything it
     * already has and takes only what it was missing. A merge is a correction
     * of duplication, not an invitation to prefer the newer typing.
     *
     * @return array<string,mixed> the surviving facility
     */
    public function mergeFacility(string $loserId, string $winnerId): array
    {
        if ($loserId === $winnerId) {
            throw new InvalidArgumentException('A facility cannot be merged into itself.');
        }

        // Both scoped, so one from another programme resolves to null here.
        $loser = Facility::query()->where('facilities.id', BinaryUuid::toBytes($loserId))->first();
        $winner = Facility::query()->where('facilities.id', BinaryUuid::toBytes($winnerId))->first();

        if ($loser === null || $winner === null) {
            throw new InvalidArgumentException('Both facilities must be in this programme.');
        }

        if ($loser->merged_into_id !== null) {
            throw new InvalidArgumentException('That facility has already been merged.');
        }

        // A chain would make "which facility is this really?" a walk of unknown
        // length, and a cycle would make it an infinite one.
        if ($winner->merged_into_id !== null) {
            throw new InvalidArgumentException(
                'That facility has itself been merged into another. Merge into the surviving one.',
            );
        }

        Capsule::connection()->transaction(function () use ($loser, $winner): void {
            TestingSite::query()
                ->where('facility_id', BinaryUuid::toBytes((string) $loser->id))
                ->update(['facility_id' => BinaryUuid::toBytes((string) $winner->id)]);

            $fill = [];

            foreach ([
                'code', 'address', 'geo_unit_id', 'facility_type', 'level', 'affiliation',
                'contact_name', 'contact_phone', 'contact_email', 'latitude', 'longitude',
            ] as $field) {
                if ($winner->{$field} === null && $loser->{$field} !== null) {
                    $fill[$field] = $loser->{$field};
                }
            }

            if ($fill !== []) {
                Facility::query()
                    ->where('facilities.id', BinaryUuid::toBytes((string) $winner->id))
                    ->update($fill);
            }

            Facility::query()
                ->where('facilities.id', BinaryUuid::toBytes((string) $loser->id))
                ->update([
                    'merged_into_id' => BinaryUuid::toBytes((string) $winner->id),
                    'is_active'      => 0,
                ]);
        });

        return $this->oneFacility($winnerId);
    }

    /**
     * The option keys a facility's type, level and affiliation may take.
     *
     * Read from the PUBLISHED TEMPLATE rather than hard-coded, because they are
     * the instrument's own vocabulary and a country may customise them. A list
     * repeated here would be one instrument revision away from offering a key
     * that scores against nothing.
     *
     * @return array<string,list<array{key: string, label: string}>>
     */
    public function facilityOptions(string $locale = 'en'): array
    {
        $template = Template::published(TenantContext::requireOrganizationId());

        if ($template === null) {
            return ['facility_type' => [], 'level' => [], 'affiliation' => []];
        }

        $definition = $template->definition;
        $definition = is_string($definition)
            ? json_decode($definition, true, 512, JSON_THROW_ON_ERROR)
            : $definition;

        $options = ['facility_type' => [], 'level' => [], 'affiliation' => []];

        foreach ((array) ($definition['context_fields'] ?? []) as $field) {
            $code = (string) ($field['code'] ?? '');

            if (!array_key_exists($code, $options)) {
                continue;
            }

            foreach ((array) ($field['options'] ?? []) as $option) {
                $label = (array) ($option['label'] ?? []);

                $options[$code][] = [
                    'key'   => (string) ($option['key'] ?? ''),
                    'label' => (string) ($label[$locale] ?? $label['en'] ?? reset($label) ?: ''),
                ];
            }
        }

        return $options;
    }

    /**
     * Testing sites, narrowed by facility, by place, or by name.
     *
     * The place filter matters more than it looks. Without it, anything
     * wanting every site in a district had to fetch the district's facilities
     * and then ask per facility — which the assignments screen did, at one
     * request per facility. In a district with two hundred facilities that is
     * two hundred requests to fill one table.
     *
     * @return array{rows: list<array<string,mixed>>, total: int, page: int, per_page: int}
     */
    public function testingSites(
        ?string $facilityId = null,
        ?int $geoUnitId = null,
        ?string $search = null,
        int $page = 1,
        int $perPage = self::PAGE_SIZE,
        ?string $id = null,
    ): array {
        $perPage = max(1, min($perPage, 200));
        $page = max(1, $page);

        $query = TestingSite::query();

        if ($id !== null) {
            $query->where('testing_sites.id', BinaryUuid::toBytes($id));
        }

        if ($facilityId !== null) {
            $query->where('facility_id', BinaryUuid::toBytes($facilityId));
        }

        if ($geoUnitId !== null) {
            // One subquery rather than a round trip per facility.
            $query->whereIn(
                'facility_id',
                Facility::query()
                    ->whereIn('geo_unit_id', $this->subtree($geoUnitId))
                    ->select('facilities.id'),
            );
        }

        $term = $search === null ? '' : trim($search);

        if ($term !== '') {
            $escaped = addcslashes($term, '%_\\');

            $query->where('name', 'like', '%' . $escaped . '%');
        }

        $total = (int) $query->count();

        $query->getQuery()->orderBy('name')->forPage($page, $perPage);

        $rows = [];
        $facilities = $this->facilityLabels();
        $places = $this->placePaths();

        foreach ($query->get() as $site) {
            $facility = $facilities[(string) $site->facility_id] ?? null;

            $rows[] = [
                'id'                   => (string) $site->id,
                'facility_id'          => (string) $site->facility_id,
                'facility_name'        => $facility['name'] ?? null,
                // Where it is, spelled out, because a bench called "Lab" tells
                // nobody anything on its own.
                'place'                => $facility === null || $facility['geo_unit_id'] === null
                    ? null
                    : ($places[$facility['geo_unit_id']] ?? null),
                'name'                 => (string) $site->name,
                'location_description' => $site->location_description,
                'source'               => (string) $site->source,
                'is_active'            => (bool) $site->is_active,
            ];
        }

        return ['rows' => $rows, 'total' => $total, 'page' => $page, 'per_page' => $perPage];
    }

    /**
     * Facility name and place by id, for labelling rows.
     *
     * @return array<string,array{name: string, geo_unit_id: int|null}>
     */
    private function facilityLabels(): array
    {
        $labels = [];

        foreach (Facility::query()->get() as $facility) {
            $labels[(string) $facility->id] = [
                'name'        => (string) $facility->name,
                'geo_unit_id' => $facility->geo_unit_id === null ? null : (int) $facility->geo_unit_id,
            ];
        }

        return $labels;
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

        // A bench can be recorded under the wrong building. Moving it is safe:
        // assessments reference the SITE, so its history travels with it.
        if (array_key_exists('facility_id', $input)) {
            $facilityId = trim((string) $input['facility_id']);

            if (!BinaryUuid::isValid($facilityId)
                || Facility::query()->where('facilities.id', BinaryUuid::toBytes($facilityId))->first() === null
            ) {
                throw new InvalidArgumentException('That facility is not in this programme.');
            }

            $attributes['facility_id'] = BinaryUuid::toBytes($facilityId);
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

    /**
     * One facility by id, for the form.
     *
     * @return array<string,mixed>
     */
    public function facility(string $id): array
    {
        return $this->oneFacility($id);
    }

    /** @return array<string,mixed> */
    private function oneFacility(string $id): array
    {
        // Looked up by id rather than scanned out of a page: with thousands of
        // facilities the first page almost never contains the one just saved.
        $found = $this->facilities(null, null, 1, 1, $id)['rows'];

        if ($found === []) {
            throw new InvalidArgumentException('No such facility in this programme.');
        }

        return $found[0];
    }

    /**
     * One testing site by id, for the form that edits it.
     *
     * @return array<string,mixed>
     */
    public function testingSite(string $id): array
    {
        return $this->oneTestingSite($id);
    }

    /** @return array<string,mixed> */
    private function oneTestingSite(string $id): array
    {
        $found = $this->testingSites(null, null, null, 1, 1, $id)['rows'];

        if ($found === []) {
            throw new InvalidArgumentException('No such testing site in this programme.');
        }

        return $found[0];
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

    /**
     * An address, checked rather than stored as typed.
     *
     * A malformed one is worse than a blank: it looks like a way to reach
     * somebody right up until the message bounces, and by then whoever entered
     * it has moved on.
     *
     * @param array<string,mixed> $input
     */
    private function optionalEmail(array $input, string $key): ?string
    {
        $value = $this->optionalText($input, $key);

        if ($value === null) {
            return null;
        }

        if (filter_var($value, FILTER_VALIDATE_EMAIL) === false) {
            throw new InvalidArgumentException('That does not look like an email address.');
        }

        return $value;
    }

    /**
     * A coordinate, bounded.
     *
     * The commonest real error is latitude and longitude the wrong way round,
     * which the range catches whenever the longitude is past 90 — not always,
     * but for free.
     *
     * @param array<string,mixed> $input
     */
    private function optionalCoordinate(array $input, string $key, float $limit): ?float
    {
        $value = $input[$key] ?? null;

        if ($value === null || $value === '') {
            return null;
        }

        if (!is_numeric($value) || abs((float) $value) > $limit) {
            throw new InvalidArgumentException(
                ucfirst($key) . ' must be between -' . $limit . ' and ' . $limit . '.',
            );
        }

        return (float) $value;
    }

    /** @param array<string,mixed> $input */
    private function optionalInt(array $input, string $key): ?int
    {
        $value = $input[$key] ?? null;

        return is_numeric($value) ? (int) $value : null;
    }
}
