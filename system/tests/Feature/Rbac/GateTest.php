<?php

use App\Enums\Permission;
use Illuminate\Support\Facades\Gate;

/*
 * Page-level Gates (planning/rbac.md, permission matrix). The expected
 * roles are written out here on purpose, not read from the enum, so a
 * change to the enum must also change this table.
 */

dataset('gate matrix', [
    'manage-users' => ['manage-users', ['admin', 'system_admin']],
    'manage-lists' => ['manage-lists', ['admin']],
    'manage-trash' => ['manage-trash', ['admin']],
    'close-month' => ['close-month', ['admin']],
    'view-audit' => ['view-audit', ['admin']],
    'view-backups' => ['view-backups', ['admin']],
    'view-reports' => ['view-reports', ['admin', 'viewer']],
    'encode-visits' => ['encode-visits', ['encoder', 'admin']],
    'view-records' => ['view-records', ['admin']],
]);

it('gives each Gate to exactly the roles in rbac.md', function (string $gate, array $allowed) {
    foreach (['encoder', 'admin', 'system_admin', 'viewer'] as $role) {
        expect(Gate::forUser(userWithRole($role))->allows($gate))
            ->toBe(in_array($role, $allowed, true), "{$gate} for {$role}");
    }
})->with('gate matrix');

it('has a row in the table above for every Gate', function () {
    $names = array_map(fn (Permission $p) => $p->value, Permission::cases());

    expect($names)->toEqualCanonicalizing([
        'manage-users', 'manage-lists', 'manage-trash', 'close-month', 'view-audit',
        'view-backups', 'view-reports', 'encode-visits', 'view-records',
    ]);
});

it('keeps resources/js/lib/permissions.ts in step with the Gates', function () {
    $source = file_get_contents(resource_path('js/lib/permissions.ts'));

    expect(preg_match('/export const PERMISSIONS = \{(.*?)\}\s*as const/s', $source, $block))->toBe(1);
    preg_match_all("/'([a-z-]+)':\s*\[([^\]]*)\]/", $block[1], $entries, PREG_SET_ORDER);

    $frontend = collect($entries)
        ->mapWithKeys(fn (array $entry) => [
            $entry[1] => collect(explode(',', $entry[2]))
                ->map(fn (string $role) => trim($role, " '\"\r\n\t"))
                ->filter()
                ->sort()
                ->values()
                ->all(),
        ])
        ->sortKeys()
        ->all();

    $backend = collect(Permission::cases())
        ->mapWithKeys(fn (Permission $permission) => [
            $permission->value => collect($permission->roles())->map->value->sort()->values()->all(),
        ])
        ->sortKeys()
        ->all();

    expect($frontend)->toBe($backend);
});
