<?php

declare(strict_types=1);

namespace Tests\Unit\Auth;

use App\Auth\Permission;
use App\Auth\Roles;
use PHPUnit\Framework\TestCase;

/**
 * The two copies of the grant map have to agree.
 *
 * There are exactly two, and they cannot be collapsed into one. The migrations
 * are SQL because that is what bin/migrate runs, and they are frozen because a
 * migration that changes after it has been applied is a migration nobody can
 * reason about. App\Auth\Roles is PHP because it applies to organisations
 * created from now on.
 *
 * If they drift, an organisation behaves differently depending on the week it
 * was provisioned — the oldest kind of bug this project can produce, invisible
 * in every test that provisions its own tenant, and reported months later as
 * "it works for them and not for us".
 *
 * So: parse the migration and compare. When this fails, one of the two was
 * edited and the other was not.
 */
final class GrantsMatchTheMigrationTest extends TestCase
{
    private const MIGRATIONS = __DIR__ . '/../../../migrations/*.sql';

    public function testTheMigrationsSeedExactlyWhatNewRolesGet(): void
    {
        self::assertSame(
            $this->normalise(Roles::GRANTS),
            $this->normalise($this->fromMigrations()),
            'the migrations and App\Auth\Roles disagree about what a role is created holding',
        );
    }

    /** Every key either side names has to be one the application knows. */
    public function testNoGrantNamesAPermissionThatDoesNotExist(): void
    {
        foreach ($this->normalise(Roles::GRANTS) as $roleKey => $permissions) {
            foreach ($permissions as $permission) {
                self::assertTrue(
                    Permission::exists($permission),
                    $roleKey . ' is granted ' . $permission . ', which is not in the catalogue',
                );
            }
        }
    }

    /** And every role either side names has to be one that gets created. */
    public function testNoGrantNamesARoleThatIsNeverSeeded(): void
    {
        foreach (array_keys($this->normalise(Roles::GRANTS)) as $roleKey) {
            self::assertArrayHasKey($roleKey, Roles::SYSTEM);
        }
    }

    /**
     * Read back out of the SQL, across every migration.
     *
     * All of them rather than the one that introduced the table, because a
     * permission added later arrives in a migration of its own — and an
     * existing installation ends up with the union of them, which is what this
     * has to compare against.
     *
     * Matches the shape the files are written in: one INSERT per permission,
     * naming the roles that hold it. Deliberately strict, so a statement
     * written some other way fails to match and the count comes out wrong.
     * That is a better outcome than a lenient parser quietly reading half of
     * them.
     *
     * @return array<string,list<string>>
     */
    private function fromMigrations(): array
    {
        $seen = [];
        $grants = [];

        foreach (glob(self::MIGRATIONS) ?: [] as $file) {
            $matched = preg_match_all(
                "/INSERT IGNORE INTO role_permissions[^;]*?SELECT id, '([a-z.]+)' FROM roles WHERE `key` (?:IN \(([^)]*)\)|= '([a-z_]+)')/i",
                (string) file_get_contents($file),
                $matches,
                PREG_SET_ORDER,
            );

            self::assertNotFalse($matched);

            foreach ($matches as $match) {
                $permission = $match[1];

                // Seeded twice means two migrations disagree about who holds
                // it, and the one that ran second silently wins.
                self::assertNotContains(
                    $permission,
                    $seen,
                    $permission . ' is seeded by more than one migration',
                );

                $seen[] = $permission;

                foreach (explode(',', $match[2] === '' ? $match[3] : $match[2]) as $roleKey) {
                    $grants[trim($roleKey, " '")][] = $permission;
                }
            }
        }

        sort($seen);
        $catalogue = Permission::all();
        sort($catalogue);

        self::assertSame(
            $catalogue,
            $seen,
            'every permission in the catalogue is seeded by exactly one migration, and no others are',
        );

        return $grants;
    }

    /**
     * Sorted, and without the roles that hold nothing.
     *
     * site_user appears in Roles::GRANTS with an empty list and cannot appear
     * in the SQL at all, because there is no row to insert for it. Comparing
     * without normalising would report a difference that is only a difference
     * in how "holds nothing" is spelled.
     *
     * @param  array<string,list<string>> $grants
     * @return array<string,list<string>>
     */
    private function normalise(array $grants): array
    {
        $normalised = [];

        foreach ($grants as $roleKey => $permissions) {
            if ($permissions === []) {
                continue;
            }

            sort($permissions);
            $normalised[$roleKey] = $permissions;
        }

        ksort($normalised);

        return $normalised;
    }
}
