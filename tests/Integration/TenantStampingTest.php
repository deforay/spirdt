<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Models\GeoUnit;
use App\Models\Role;
use App\Tenancy\TenantContext;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Model;
use PHPUnit\Framework\TestCase;
use Tests\Support\MakesTenants;

/**
 * The tenancy traits stamp the tenant on insert, and that has to keep working.
 *
 * It did not work for a long time and nothing said so. Both traits register a
 * `creating` hook, and standalone Illuminate fires no model events at all
 * unless a dispatcher is wired onto Capsule — so the hook was registered,
 * never called, and every row that came out right did so because the call site
 * happened to set the column by hand.
 *
 * The failure mode is the worst shape available: silent, and it produces rows
 * with no tenant, which in a shared-schema system is a row belonging to
 * everybody. So the dispatcher itself is asserted here as well as its effect —
 * losing it again would otherwise only show up as a foreign key error in
 * whichever feature was written next.
 */
final class TenantStampingTest extends TestCase
{
    use MakesTenants;

    private int $orgId;

    protected function setUp(): void
    {
        \App\Bootstrap::createApp();
        TenantContext::forget();

        TenantContext::withoutScope(function (): void {
            Capsule::connection()->statement('SET FOREIGN_KEY_CHECKS = 0');
            foreach (['geo_units', 'role_permissions', 'roles', 'organizations', 'programmes'] as $table) {
                Capsule::table($table)->delete();
            }
            Capsule::connection()->statement('SET FOREIGN_KEY_CHECKS = 1');
        });

        $this->orgId = $this->makeTenant('stamp-org', 'Stamping');
        $this->useTenant($this->orgId);
    }

    protected function tearDown(): void
    {
        TenantContext::forget();
    }

    /**
     * Booting the app more than once must not cost the listeners.
     *
     * A model registers on whichever dispatcher is current when its class
     * first boots, and Eloquent boots a class once — so a fresh dispatcher on
     * the second createApp() strands them.
     */
    public function testModelEventsSurviveRepeatedBootstrapping(): void
    {
        $first = Model::getEventDispatcher();

        \App\Bootstrap::createApp();
        \App\Bootstrap::createApp();

        self::assertNotNull($first);
        self::assertSame($first, Model::getEventDispatcher());
    }

    public function testAProgrammeScopedRowIsStampedWithoutBeingTold(): void
    {
        $unit = new GeoUnit();
        $unit->fill(['level' => 'Province', 'name' => 'Copperbelt', 'is_active' => 1]);
        $unit->save();

        self::assertSame(
            $this->programmeFor($this->orgId),
            (int) GeoUnit::query()->where('geo_units.id', (int) $unit->id)->value('programme_id'),
        );
    }

    public function testAnOrganisationScopedRowIsStampedWithoutBeingTold(): void
    {
        $role = new Role();
        $role->fill(['key' => 'stamped', 'name' => 'Stamped', 'is_system' => 0]);
        $role->save();

        self::assertSame(
            $this->orgId,
            (int) Role::query()->where('roles.id', (int) $role->id)->value('organization_id'),
        );
    }

    /** An explicit tenant is left alone — the hook fills a gap, it does not overrule. */
    public function testAnExplicitTenantIsNotOverwritten(): void
    {
        $other = $this->makeTenant('stamp-other', 'Other');

        $unit = new GeoUnit();
        $unit->fill([
            'programme_id' => $this->programmeFor($other),
            'level'        => 'Province',
            'name'         => 'Elsewhere',
        ]);
        $unit->save();

        self::assertSame(
            $this->programmeFor($other),
            (int) GeoUnit::acrossProgrammes()->where('geo_units.id', (int) $unit->id)->value('programme_id'),
        );
    }
}
