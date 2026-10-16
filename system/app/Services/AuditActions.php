<?php

namespace App\Services;

/**
 * Plain-language names for audit_logs action codes, in one place. The Audit
 * log screen shows the label and groups the Action filter by GROUPS.
 * A code that is not listed here shows as the code itself.
 */
final class AuditActions
{
    public const VISITS = 'Visits';

    public const PATIENTS = 'Patients';

    public const MONTHS = 'Months';

    public const LISTS = 'Lists';

    public const USERS = 'Users & logins';

    public const TRASH = 'Trash';

    public const BACKUPS = 'Backups';

    public const SETTINGS = 'Settings';

    public const SYSTEM = 'System';

    /** Order of the groups in the Action filter. */
    public const GROUPS = [self::VISITS, self::PATIENTS, self::MONTHS, self::LISTS, self::USERS, self::TRASH, self::BACKUPS, self::SETTINGS, self::SYSTEM];

    /**
     * Code => [label, group].
     *
     * @var array<string, array{0: string, 1: string}>
     */
    private const MAP = [
        'visit.created' => ['Visit encoded', self::VISITS],
        'visit.updated' => ['Visit edited', self::VISITS],
        'patient.created' => ['Patient added', self::PATIENTS],
        'patient.updated' => ['Patient edited', self::PATIENTS],

        'month.closed' => ['Month closed', self::MONTHS],
        'month.reopened' => ['Month reopened', self::MONTHS],
        'report.exported' => ['Report exported', self::MONTHS],

        'diagnosis.created' => ['Diagnosis added', self::LISTS],
        'diagnosis.updated' => ['Diagnosis edited', self::LISTS],
        'rank.created' => ['Rank / type added', self::LISTS],
        'rank.updated' => ['Rank / type edited', self::LISTS],
        'age_bracket.created' => ['Age bracket added', self::LISTS],
        'age_bracket.updated' => ['Age bracket edited', self::LISTS],

        'user.created' => ['User account created', self::USERS],
        'user.updated' => ['User account edited', self::USERS],
        'user.login' => ['Signed in', self::USERS],
        'user.login_failed' => ['Wrong password', self::USERS],
        'user.locked' => ['Account locked', self::USERS],
        'user.unlocked' => ['User unlocked', self::USERS],
        'user.logout' => ['Signed out', self::USERS],
        'user.session_ended' => ['Signed out by the system', self::USERS],
        'user.password_reset' => ['Password reset', self::USERS],
        'user.password_changed' => ['Password changed', self::USERS],
        'user.activated' => ['Account activated', self::USERS],
        'user.deactivated' => ['Account deactivated', self::USERS],

        'backup.run_manual' => ['Backup run manually', self::BACKUPS],
        'backup.usb_copy_marked' => ['USB copy marked as done', self::BACKUPS],
        'backup.test_restore_marked' => ['Test restore marked as done', self::BACKUPS],

        'settings.updated' => ['Hospital settings saved', self::SETTINGS],
        'settings.logo_changed' => ['Hospital logo changed', self::SETTINGS],
        'settings.logo_removed' => ['Hospital logo removed', self::SETTINGS],
        'settings.signature_changed' => ['Signature picture changed', self::SETTINGS],
        'settings.signature_removed' => ['Signature picture removed', self::SETTINGS],

        'system.updated' => ['System updated', self::SYSTEM],
        'system.restored' => ['System restored from a backup', self::SYSTEM],

        'visit.deleted' => ['Visit moved to Trash', self::TRASH],
        'visit.restored' => ['Visit restored', self::TRASH],
        'visit.purged' => ['Visit deleted permanently', self::TRASH],
        'patient.deleted' => ['Patient moved to Trash', self::TRASH],
        'patient.restored' => ['Patient restored', self::TRASH],
        'patient.purged' => ['Patient deleted permanently', self::TRASH],
        'user.deleted' => ['User account moved to Trash', self::TRASH],
        'user.restored' => ['User account restored', self::TRASH],
        'user.purged' => ['User account deleted permanently', self::TRASH],
        'diagnosis.deleted' => ['Diagnosis moved to Trash', self::TRASH],
        'diagnosis.restored' => ['Diagnosis restored', self::TRASH],
        'diagnosis.purged' => ['Diagnosis deleted permanently', self::TRASH],
        'rank.deleted' => ['Rank / type moved to Trash', self::TRASH],
        'rank.restored' => ['Rank / type restored', self::TRASH],
        'rank.purged' => ['Rank / type deleted permanently', self::TRASH],
        'age_bracket.deleted' => ['Age bracket moved to Trash', self::TRASH],
        'age_bracket.restored' => ['Age bracket restored', self::TRASH],
        'age_bracket.purged' => ['Age bracket deleted permanently', self::TRASH],
        'month.report_deleted' => ['Month report moved to Trash', self::TRASH],
        'month.report_restored' => ['Month report restored', self::TRASH],
        'month.report_purged' => ['Month report deleted permanently', self::TRASH],
    ];

    /** Why the system signed someone out (changes.reason of user.session_ended). */
    private const SESSION_ENDED = [
        'daily_cutoff' => 'Signed out (8:00 PM daily sign-out)',
        'deactivated' => 'Signed out (account deactivated)',
        'locked' => 'Signed out (account locked)',
        'trashed' => 'Signed out (account moved to Trash)',
        'deleted' => 'Signed out (account deleted)',
    ];

    /**
     * The label for one entry. An unknown code shows as itself.
     *
     * @param  array<string, mixed>|null  $changes
     */
    public static function label(string $code, ?array $changes = null): string
    {
        if ($code === 'user.session_ended') {
            $reason = $changes['reason'] ?? null;

            if (is_string($reason) && isset(self::SESSION_ENDED[$reason])) {
                return self::SESSION_ENDED[$reason];
            }
        }

        return self::MAP[$code][0] ?? $code;
    }

    /** @return list<string> */
    public static function codes(): array
    {
        return array_keys(self::MAP);
    }

    /**
     * The Action filter: groups of {value: code, label}.
     *
     * @return list<array{group: string, options: list<array{value: string, label: string}>}>
     */
    public static function options(): array
    {
        $groups = [];

        foreach (self::MAP as $code => [$label, $group]) {
            $groups[$group][] = ['value' => $code, 'label' => $label];
        }

        return array_map(fn (string $group): array => ['group' => $group, 'options' => $groups[$group]], self::GROUPS);
    }
}
