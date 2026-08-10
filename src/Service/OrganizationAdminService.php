<?php

declare(strict_types=1);

namespace App\Service;

use App\Audit\AuditAction;
use App\Audit\AuditLog;
use App\Auth\Roles;
use App\Models\Organization;
use App\Models\Programme;
use App\Models\Role;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Database\Capsule\Manager as Capsule;
use InvalidArgumentException;

/**
 * The organisations auditing under one programme.
 *
 * The shape this serves is the one that is already clear: a country has
 * several independent organisations, each with its own assessors, auditing the
 * same labs or different ones. They share the site registry — that is what
 * makes their results comparable — and share nothing else.
 *
 * WHO MAY DO THIS. A `superadmin`, and only within their own programme. The
 * role was seeded into every organisation from the start and assigned to
 * nobody; it is the natural place for this and needs no new vocabulary while
 * the hierarchy is still settling. In practice the superadmin sits in the
 * organisation that owns the programme — a ministry's own team — and the other
 * organisations are the partners auditing alongside it.
 *
 * The programme is never taken from a request. It comes from the token, so
 * this cannot reach across into another country's tenants however the body is
 * shaped.
 */
final class OrganizationAdminService
{
    /**
     * Everyone in this programme, with enough to see whether they are working.
     *
     * @return list<array<string,mixed>>
     */
    public function list(): array
    {
        $programmeId = TenantContext::requireProgrammeId();

        $rows = [];

        $query = Organization::query()->where('programme_id', $programmeId);
        $query->getQuery()->orderBy('name');

        foreach ($query->get() as $organization) {
            $id = (int) $organization->id;

            $rows[] = [
                'id'            => $id,
                'code'          => (string) $organization->code,
                'name'          => (string) $organization->name,
                'country_code'  => $organization->country_code,
                'timezone'      => (string) $organization->timezone,
                'is_active'     => (bool) $organization->is_active,
                // What an administrator actually wants to know at a glance:
                // whether anybody is using it, and whether anybody can.
                'user_count'    => $this->countUsers($id, active: null),
                'active_admins' => $this->countAdministrators($id),
                'assessments'   => $this->countAssessments($id),
            ];
        }

        return $rows;
    }

    /**
     * Add an organisation to this programme, with the administrator who will
     * run it.
     *
     * Both in one step deliberately. An organisation with nobody able to
     * administer it is the state bin/recover-access exists to dig out of, and
     * creating one in that state and hoping somebody remembers the second step
     * is how it happens.
     *
     * @param  array<string,mixed> $input
     * @return array{organization: array<string,mixed>, password: string}
     */
    public function create(array $input): array
    {
        $programmeId = TenantContext::requireProgrammeId();

        $code = mb_strtolower(trim((string) ($input['code'] ?? '')));
        $name = trim((string) ($input['name'] ?? ''));
        $adminEmail = mb_strtolower(trim((string) ($input['admin_email'] ?? '')));
        $adminName = trim((string) ($input['admin_name'] ?? ''));

        if (preg_match('/^[a-z0-9][a-z0-9-]{1,49}$/', $code) !== 1) {
            throw new InvalidArgumentException(
                'Use lowercase letters, digits and hyphens for the code.',
            );
        }

        if ($name === '') {
            throw new InvalidArgumentException('A name is required.');
        }

        if (filter_var($adminEmail, FILTER_VALIDATE_EMAIL) === false) {
            throw new InvalidArgumentException('That does not look like an email address.');
        }

        if ($adminName === '') {
            throw new InvalidArgumentException("The administrator's full name is required.");
        }

        // Codes are unique across the whole installation, not just this
        // programme: they are typed at sign-in to disambiguate an address that
        // exists in more than one organisation, so two the same would make
        // that ambiguous again. Checked across programmes on purpose.
        $taken = Organization::query()->where('code', $code)->first() !== null;

        if ($taken) {
            throw new InvalidArgumentException('That code is already in use.');
        }

        $password = rtrim(strtr(base64_encode(random_bytes(18)), '+/', '-_'), '=');
        $programme = Programme::query()->where('id', $programmeId)->first();

        $organizationId = Capsule::connection()->transaction(function () use (
            $programmeId,
            $programme,
            $code,
            $name,
            $adminEmail,
            $adminName,
            $password,
            $input,
        ): int {
            $organization = new Organization();
            $organization->fill([
                'programme_id'   => $programmeId,
                'code'           => $code,
                'name'           => $name,
                // Inherited from the programme unless overridden. A country's
                // organisations are in that country, and asking again invites
                // one of them to disagree with the others.
                'country_code'   => $this->optional($input, 'country_code')
                    ?? $programme?->country_code,
                'timezone'       => $this->optional($input, 'timezone') ?? 'UTC',
                'date_format'    => $this->optional($input, 'date_format') ?? 'd/m/Y',
                'default_locale' => $this->optional($input, 'locale') ?? 'en',
                'is_active'      => 1,
            ]);
            $organization->save();

            $newId = (int) $organization->id;

            foreach (Roles::SYSTEM as $key => $label) {
                $role = new Role();
                $role->fill([
                    'organization_id' => $newId,
                    'key'             => $key,
                    'name'            => $label,
                    'is_system'       => 1,
                ]);
                $role->save();

                // In the same transaction as the role itself. A role that
                // exists without its permissions is one nobody can use, and an
                // administrator looking at it has no way to tell that from a
                // role somebody deliberately emptied.
                Roles::seed((int) $role->id, $key);
            }

            $roleId = Role::acrossOrganizations()
                ->where('roles.organization_id', $newId)
                ->where('roles.key', 'admin')
                ->value('id');

            $user = new User();
            $user->fill([
                'organization_id'      => $newId,
                'role_id'              => (int) $roleId,
                'email'                => $adminEmail,
                'password_hash'        => password_hash($password, PASSWORD_DEFAULT),
                'full_name'            => $adminName,
                'is_active'            => 1,
                // Somebody else chose it and has seen it.
                'must_change_password' => 1,
            ]);
            $user->save();

            return $newId;
        });

        AuditLog::record(AuditAction::ORGANIZATION_CREATED, 'organization', $organizationId, [
            'code'        => $code,
            'admin_email' => $adminEmail,
        ]);

        return ['organization' => $this->one($organizationId), 'password' => $password];
    }

    /**
     * Switch an organisation on or off, or rename it.
     *
     * @param  array<string,mixed> $input
     * @return array<string,mixed>
     */
    public function update(int $organizationId, array $input): array
    {
        $programmeId = TenantContext::requireProgrammeId();

        $organization = Organization::query()
            ->where('id', $organizationId)
            ->where('programme_id', $programmeId)
            ->first();

        if ($organization === null) {
            throw new InvalidArgumentException('No such organisation in this programme.');
        }

        // Switching off your own organisation signs you out of the tool you
        // would need to switch it back on.
        if ($organizationId === TenantContext::requireOrganizationId()
            && array_key_exists('is_active', $input)
            && (bool) $input['is_active'] === false
        ) {
            throw new InvalidArgumentException('You cannot switch off your own organisation.');
        }

        $attributes = [];

        if (array_key_exists('name', $input)) {
            $name = trim((string) $input['name']);

            if ($name === '') {
                throw new InvalidArgumentException('A name is required.');
            }

            $attributes['name'] = $name;
        }

        foreach (['timezone', 'date_format', 'country_code'] as $field) {
            if (array_key_exists($field, $input)) {
                $attributes[$field] = $this->optional($input, $field);
            }
        }

        if (array_key_exists('is_active', $input)) {
            $attributes['is_active'] = (bool) $input['is_active'] ? 1 : 0;
        }

        if ($attributes !== []) {
            Organization::query()->where('id', $organizationId)->update($attributes);

            $detail = ['changed' => array_keys($attributes)];

            // Deactivation is the one that stops everybody in that
            // organisation signing in, so it is named rather than left as a
            // field in a list.
            if (array_key_exists('is_active', $attributes)) {
                $detail['is_active'] = (bool) $attributes['is_active'];
            }

            AuditLog::record(AuditAction::ORGANIZATION_UPDATED, 'organization', $organizationId, $detail);
        }

        return $this->one($organizationId);
    }

    /** @return array<string,mixed> */
    private function one(int $organizationId): array
    {
        foreach ($this->list() as $row) {
            if ($row['id'] === $organizationId) {
                return $row;
            }
        }

        throw new InvalidArgumentException('No such organisation in this programme.');
    }

    /**
     * Counted across organisations on purpose: these are other tenants' rows,
     * which the scope would otherwise hide from the person responsible for
     * them.
     */
    private function countUsers(int $organizationId, ?bool $active): int
    {
        $query = User::acrossOrganizations()->where('users.organization_id', $organizationId);

        if ($active !== null) {
            $query->where('users.is_active', $active ? 1 : 0);
        }

        return $query->count();
    }

    private function countAdministrators(int $organizationId): int
    {
        $roleIds = Role::acrossOrganizations()
            ->where('roles.organization_id', $organizationId)
            ->whereIn('roles.key', ['admin', 'superadmin'])
            ->pluck('id')
            ->all();

        if ($roleIds === []) {
            return 0;
        }

        return User::acrossOrganizations()
            ->where('users.organization_id', $organizationId)
            ->where('users.is_active', 1)
            ->whereIn('users.role_id', $roleIds)
            ->count();
    }

    private function countAssessments(int $organizationId): int
    {
        return (int) Capsule::table('assessments')
            ->where('organization_id', $organizationId)
            ->count();
    }

    /** @param array<string,mixed> $input */
    private function optional(array $input, string $key): ?string
    {
        $value = trim((string) ($input[$key] ?? ''));

        return $value === '' ? null : $value;
    }
}
