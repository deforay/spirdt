<?php

declare(strict_types=1);

namespace App\Service;

use App\Audit\AuditAction;
use App\Audit\AuditLog;
use App\Models\Organization;
use App\Support\Secret;
use App\Tenancy\TenantContext;
use DateTimeZone;
use Illuminate\Database\Capsule\Manager as Capsule;
use InvalidArgumentException;
use RuntimeException;

/**
 * How this installation is set up, and how this organisation reads.
 *
 * ONE SCREEN, TWO STORES, AND THE SPLIT IS NOT AN ACCIDENT.
 *
 * The instance block and the mail block go to `system_config`, which is shared
 * across every organisation on the installation — there is one name for the
 * deployment and one mail server behind it, and asking each tenant for its own
 * would produce a mailbox per tenant that nobody administers.
 *
 * The localisation block goes to the caller's own `organizations` row, where
 * the timezone and the language have lived since 0.1.0. Those are genuinely per
 * organisation: two partners auditing in the same country can sit in different
 * timezones, and the audit trail already reads that column to work out where a
 * day begins. Copying them into system_config would give the same question two
 * answers and make this screen the thing that made them disagree.
 *
 * WHAT IS READ AND WHAT IS MERELY KEPT. Timezone is read by the audit trail.
 * Name, contact and language ride on the sign-in response, so the frame shows
 * the installation's name, the no-access screen can say who to ask, and a
 * device with no language of its own starts in the organisation's. The mail
 * settings are stored and nothing sends mail yet — the screen says so rather
 * than implying a message would go out.
 */
final class SettingsService
{
    /**
     * The catalogues the app ships, mirroring web/src/i18n.
     *
     * Listed here so the server refuses a language the app cannot render.
     * Storing an unsupported code would fall back silently at every render and
     * leave an administrator certain they had set something.
     */
    public const LOCALES = ['en', 'fr', 'pt', 'es'];

    /** What an SMTP connection can be wrapped in. */
    public const ENCRYPTIONS = ['none', 'tls', 'ssl'];

    /** Instance-wide keys in system_config, and their defaults. */
    private const INSTANCE_KEYS = [
        'instance.name'          => '',
        'instance.contact_name'  => '',
        'instance.contact_email' => '',
    ];

    /** The mail block. `smtp.password` is handled apart from these — it is never read back. */
    private const MAIL_KEYS = [
        'smtp.host'         => '',
        'smtp.port'         => '587',
        'smtp.encryption'   => 'tls',
        'smtp.username'     => '',
        'smtp.from_address' => '',
        'smtp.from_name'    => '',
    ];

    /**
     * Everything the settings screen shows.
     *
     * The SMTP password is not in here and never will be. What comes back is
     * whether one is stored, which is the only thing the screen needs in order
     * to say "set" rather than "not set" — and the only thing a request that
     * can be replayed should be willing to disclose.
     *
     * @return array<string,mixed>
     */
    public function read(): array
    {
        $stored = $this->rows();
        $organization = $this->organization();

        return [
            'instance' => [
                'name'          => $stored['instance.name'],
                'contact_name'  => $stored['instance.contact_name'],
                'contact_email' => $stored['instance.contact_email'],
            ],
            'localisation' => [
                'timezone'     => (string) $organization->timezone,
                'locale'       => (string) $organization->default_locale,
                'country_code' => $organization->country_code === null
                    ? ''
                    : (string) $organization->country_code,
                // Named, because the block edits one organisation's settings
                // rather than the installation's and the screen has to say
                // whose. On a single-tenant install this is the only one there
                // is; on a shared one it is the superadmin's own.
                'organization' => (string) $organization->name,
            ],
            'mail' => [
                'host'         => $stored['smtp.host'],
                'port'         => (int) $stored['smtp.port'],
                'encryption'   => $stored['smtp.encryption'],
                'username'     => $stored['smtp.username'],
                'from_address' => $stored['smtp.from_address'],
                'from_name'    => $stored['smtp.from_name'],
                'has_password' => $this->value('smtp.password') !== '',
            ],
            // So the screen can explain why the password field refuses to save
            // before somebody types into it, rather than after.
            'can_store_password' => Secret::available(),
            'timezones'          => DateTimeZone::listIdentifiers(),
            'locales'            => self::LOCALES,
            'encryptions'        => self::ENCRYPTIONS,
        ];
    }

    /**
     * Save whatever the screen sent, and nothing it did not.
     *
     * Every field is optional and absence means "leave it alone", so a screen
     * that only knows about half of these — an older build, or a later one
     * partway through a deploy — cannot blank the other half by omitting it.
     *
     * @param  array<string,mixed> $input
     * @return array<string,mixed>
     */
    public function update(array $input): array
    {
        $changed = [];

        $instance = $this->section($input, 'instance');
        $localisation = $this->section($input, 'localisation');
        $mail = $this->section($input, 'mail');

        foreach (['name', 'contact_name'] as $field) {
            if (array_key_exists($field, $instance)) {
                $changed[] = $this->put('instance.' . $field, $this->text($instance[$field], 200));
            }
        }

        if (array_key_exists('contact_email', $instance)) {
            $changed[] = $this->put(
                'instance.contact_email',
                $this->email($instance['contact_email'], 'The contact address does not look like an email address.'),
            );
        }

        $changed = [...$changed, ...$this->updateLocalisation($localisation)];
        $changed = [...$changed, ...$this->updateMail($mail)];

        $changed = array_values(array_filter($changed, static fn (?string $key): bool => $key !== null));

        if ($changed !== []) {
            AuditLog::record(AuditAction::SETTINGS_UPDATED, 'settings', null, ['changed' => $changed]);
        }

        return $this->read();
    }

    /**
     * The part of the instance settings that everybody may see.
     *
     * Rides on the sign-in and refresh responses, so the app can name the
     * installation in its frame and the no-access screen can name somebody to
     * ask. None of it is secret — it is the deployment's own name and a contact
     * address a person locked out of everything else needs most.
     *
     * Static and tenant-free: sign-in has not established an organisation at
     * the point it is called.
     *
     * @return array<string,string>
     */
    public static function publicInstance(): array
    {
        $rows = Capsule::table('system_config')
            ->whereIn('key', array_keys(self::INSTANCE_KEYS))
            ->pluck('value', 'key')
            ->all();

        return [
            'name'          => (string) ($rows['instance.name'] ?? ''),
            'contact_name'  => (string) ($rows['instance.contact_name'] ?? ''),
            'contact_email' => (string) ($rows['instance.contact_email'] ?? ''),
        ];
    }

    /**
     * The SMTP settings, with the password decrypted.
     *
     * Nothing calls this yet — there is no mailer. It is here because it is the
     * one place that should ever hold a decrypted password, and leaving the
     * decision to whoever builds the first email is how a second copy of it
     * appears somewhere else.
     *
     * @return array<string,mixed>
     */
    public function mailer(): array
    {
        $stored = $this->rows();

        return [
            'host'         => $stored['smtp.host'],
            'port'         => (int) $stored['smtp.port'],
            'encryption'   => $stored['smtp.encryption'],
            'username'     => $stored['smtp.username'],
            'password'     => Secret::decrypt($this->value('smtp.password')),
            'from_address' => $stored['smtp.from_address'],
            'from_name'    => $stored['smtp.from_name'],
        ];
    }

    /**
     * @param  array<string,mixed> $input
     * @return list<string|null>
     */
    private function updateLocalisation(array $input): array
    {
        $attributes = [];

        if (array_key_exists('timezone', $input)) {
            $timezone = $this->text($input['timezone'], 64);

            if (!in_array($timezone, DateTimeZone::listIdentifiers(), true)) {
                throw new InvalidArgumentException('That is not a timezone this server knows.');
            }

            $attributes['timezone'] = $timezone;
        }

        if (array_key_exists('locale', $input)) {
            $locale = $this->text($input['locale'], 10);

            if (!in_array($locale, self::LOCALES, true)) {
                throw new InvalidArgumentException('The app has no translations for that language.');
            }

            $attributes['default_locale'] = $locale;
        }

        if (array_key_exists('country_code', $input)) {
            $country = mb_strtoupper($this->text($input['country_code'], 2));

            if ($country !== '' && preg_match('/^[A-Z]{2}$/', $country) !== 1) {
                throw new InvalidArgumentException('A country is two letters, as in ZM or KE.');
            }

            $attributes['country_code'] = $country === '' ? null : $country;
        }

        if ($attributes === []) {
            return [];
        }

        Organization::query()
            ->where('id', TenantContext::requireOrganizationId())
            ->update($attributes);

        return array_map(static fn (string $key): string => 'localisation.' . $key, array_keys($attributes));
    }

    /**
     * @param  array<string,mixed> $input
     * @return list<string|null>
     */
    private function updateMail(array $input): array
    {
        $changed = [];

        foreach (['host', 'username', 'from_name'] as $field) {
            if (array_key_exists($field, $input)) {
                $changed[] = $this->put('smtp.' . $field, $this->text($input[$field], 200));
            }
        }

        if (array_key_exists('from_address', $input)) {
            $changed[] = $this->put(
                'smtp.from_address',
                $this->email($input['from_address'], 'The from address does not look like an email address.'),
            );
        }

        if (array_key_exists('port', $input)) {
            $port = (int) $input['port'];

            if ($port < 1 || $port > 65535) {
                throw new InvalidArgumentException('A port is a number between 1 and 65535.');
            }

            $changed[] = $this->put('smtp.port', (string) $port);
        }

        if (array_key_exists('encryption', $input)) {
            $encryption = $this->text($input['encryption'], 10);

            if (!in_array($encryption, self::ENCRYPTIONS, true)) {
                throw new InvalidArgumentException('Encryption is one of none, tls or ssl.');
            }

            $changed[] = $this->put('smtp.encryption', $encryption);
        }

        // AN EMPTY PASSWORD MEANS "LEAVE IT", NOT "CLEAR IT". The screen never
        // receives the stored one, so it has nothing to send back, and treating
        // the blank box it necessarily renders as an instruction would wipe the
        // password every time somebody corrected the port. Clearing it is its
        // own field for that reason.
        if (array_key_exists('password', $input) && $this->text($input['password'], 400) !== '') {
            try {
                $changed[] = $this->put('smtp.password', Secret::encrypt($this->text($input['password'], 400)));
            } catch (RuntimeException $e) {
                throw new InvalidArgumentException($e->getMessage(), 0, $e);
            }
        }

        if (array_key_exists('clear_password', $input) && (bool) $input['clear_password']) {
            $changed[] = $this->put('smtp.password', '');
        }

        return $changed;
    }

    /**
     * Write one key, and say so only if it actually changed.
     *
     * The audit row lists what a save altered, and a screen that posts every
     * field on every save would otherwise record ten changes for one edit —
     * which is the same as recording none, because nobody can see which one
     * mattered.
     */
    private function put(string $key, string $value): ?string
    {
        if ($this->value($key) === $value) {
            return null;
        }

        Capsule::table('system_config')->updateOrInsert(['key' => $key], ['value' => $value]);

        return $key;
    }

    private function value(string $key): string
    {
        $stored = Capsule::table('system_config')->where('key', $key)->value('value');

        return $stored === null ? '' : (string) $stored;
    }

    /**
     * Every instance and mail key, with the defaults filled in for the ones
     * nobody has set. A screen reading a missing row as an empty string would
     * show port 0 on a fresh installation.
     *
     * @return array<string,string>
     */
    private function rows(): array
    {
        $defaults = [...self::INSTANCE_KEYS, ...self::MAIL_KEYS];

        $stored = Capsule::table('system_config')
            ->whereIn('key', array_keys($defaults))
            ->pluck('value', 'key')
            ->all();

        foreach ($defaults as $key => $default) {
            $value = (string) ($stored[$key] ?? '');
            $defaults[$key] = $value === '' ? $default : $value;
        }

        return $defaults;
    }

    private function organization(): Organization
    {
        $organization = Organization::query()
            ->where('id', TenantContext::requireOrganizationId())
            ->first();

        if (!$organization instanceof Organization) {
            throw new InvalidArgumentException('This account has no organisation.');
        }

        return $organization;
    }

    /**
     * @param  array<string,mixed> $input
     * @return array<string,mixed>
     */
    private function section(array $input, string $key): array
    {
        $section = $input[$key] ?? [];

        return is_array($section) ? $section : [];
    }

    private function text(mixed $value, int $limit): string
    {
        return mb_substr(trim((string) $value), 0, $limit);
    }

    /** An empty address is allowed and means "none set". A wrong one is not. */
    private function email(mixed $value, string $message): string
    {
        $address = mb_strtolower($this->text($value, 200));

        if ($address !== '' && filter_var($address, FILTER_VALIDATE_EMAIL) === false) {
            throw new InvalidArgumentException($message);
        }

        return $address;
    }
}
