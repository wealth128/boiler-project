<?php

use App\Models\User;
use App\Services\TablePaging;
use Inertia\Testing\AssertableInertia as Assert;

/*
 * Step 7b: the same paging on every long table. Rows per page is 10, 20 or
 * 50 from ?per_page= and anything else is 10. The page buttons show at most
 * three numbers. User accounts are paged. (System health: tests/Feature/System/HealthTest.php.)
 */

// --- Rows per page on every list ---------------------------------------------

dataset('paged lists', [
    'Records > Visits' => ['/records/visits', 'visits.meta.per_page'],
    'Records > Patients' => ['/records/patients', 'patients.meta.per_page'],
    'Records > Import > Past imports' => ['/records/import', 'batches.meta.per_page'],
    'Trash' => ['/trash', 'items.meta.per_page'],
    'Audit log' => ['/audit-log', 'entries.meta.per_page'],
    'Backups history' => ['/backups', 'runs.meta.per_page'],
    'User accounts' => ['/users', 'users.meta.per_page'],
    'System health' => ['/system/health', 'checks.meta.per_page'],
]);

dataset('per page values', [
    '10' => ['per_page=10', 10],
    '20' => ['per_page=20', 20],
    '50' => ['per_page=50', 50],
    'missing' => ['', 10],
    '5' => ['per_page=5', 10],
    '100' => ['per_page=100', 10],
    '999' => ['per_page=999', 10],
    '0' => ['per_page=0', 10],
    'negative' => ['per_page=-10', 10],
    'text' => ['per_page=abc', 10],
    'decimal' => ['per_page=20.5', 10],
    'a list' => ['per_page[]=20', 10],
]);

it('accepts only 10, 20 or 50 rows per page on every long list', function (string $url, string $path, string $query, int $expected) {
    $this->actingAs(userWithRole('admin'))
        ->get($url.($query === '' ? '' : '?'.$query))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where($path, $expected));
})->with('paged lists')->with('per page values');

// --- The page numbers: never more than three -----------------------------------

it('shows at most three page numbers around the current page', function (int $current, int $last, array $expected) {
    expect(TablePaging::window($current, $last))->toBe($expected);
})->with([
    'one page' => [1, 1, [1]],
    'two pages' => [2, 2, [1, 2]],
    'first of many' => [1, 20, [1, 2, 3]],
    'second' => [2, 20, [1, 2, 3]],
    'middle' => [5, 20, [4, 5, 6]],
    'next to last' => [19, 20, [18, 19, 20]],
    'last' => [20, 20, [18, 19, 20]],
    'three pages, middle' => [2, 3, [1, 2, 3]],
    'far beyond' => [5000, 9999, [4999, 5000, 5001]],
    'past the end is clamped' => [50, 20, [18, 19, 20]],
    'below 1 is clamped' => [0, 20, [1, 2, 3]],
]);

it('never gives more than three numbers for any page of any size', function () {
    foreach ([1, 2, 3, 4, 7, 50, 201] as $last) {
        foreach (range(1, $last) as $current) {
            $window = TablePaging::window($current, $last);

            expect(count($window))->toBeLessThanOrEqual(3)
                ->and($window)->toContain($current)
                ->and(min($window))->toBeGreaterThanOrEqual(1)
                ->and(max($window))->toBeLessThanOrEqual($last);
        }
    }
});

// --- User accounts are paged -------------------------------------------------

/** Admin plus enough encoders to make $total accounts, named so the order is known. */
function accountsTotalling(int $total): User
{
    $admin = userWithRole('admin', ['name' => 'A. Admin']);

    for ($i = 1; $i < $total; $i++) {
        userWithRole('encoder', ['name' => sprintf('Encoder %03d', $i)]);
    }

    return $admin;
}

it('pages the user accounts 10 at a time, by name', function () {
    $admin = accountsTotalling(25);

    $this->actingAs($admin)->get('/users')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('users.data', 10)
            ->where('users.data.0.name', 'A. Admin')
            ->where('users.data.1.name', 'Encoder 001')
            ->where('users.meta', [
                'current_page' => 1, 'last_page' => 3, 'per_page' => 10,
                'total' => 25, 'from' => 1, 'to' => 10, 'pages' => [1, 2, 3],
            ]));

    $this->get('/users?page=3')
        ->assertInertia(fn (Assert $page) => $page
            ->has('users.data', 5)
            ->where('users.meta.from', 21)
            ->where('users.meta.to', 25));

    $this->get('/users?per_page=20')
        ->assertInertia(fn (Assert $page) => $page
            ->has('users.data', 20)
            ->where('users.meta.last_page', 2));
});

it('sends a page past the end of the user accounts to the last page and keeps the rows per page', function () {
    $admin = accountsTotalling(25);

    $this->actingAs($admin)->get('/users?page=99')->assertRedirect('/users?page=3');
    $this->get('/users?per_page=20&page=99')->assertRedirect('/users?per_page=20&page=2');
});

it('jumps to any page: page 5 of 6 shows 4 5 6, and a far page is reachable', function () {
    $admin = accountsTotalling(60);

    $this->actingAs($admin)->get('/users?page=5')
        ->assertInertia(fn (Assert $page) => $page
            ->where('users.meta.current_page', 5)
            ->where('users.meta.last_page', 6)
            ->where('users.meta.pages', [4, 5, 6]));

    $this->get('/users?page=3')
        ->assertInertia(fn (Assert $page) => $page->where('users.meta.pages', [2, 3, 4]));

    $this->get('/users?page=1')
        ->assertInertia(fn (Assert $page) => $page->where('users.meta.pages', [1, 2, 3]));
});

it('lets the System Admin see the paged user accounts too', function () {
    accountsTotalling(12);

    $this->actingAs(userWithRole('system_admin', ['name' => 'Z. Sysadmin']))->get('/users')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('users.data', 10)
            ->where('users.meta.total', 13));
});

it('keeps the user list on the same page after an action', function () {
    $admin = accountsTotalling(25);
    $target = User::query()->where('name', 'Encoder 015')->firstOrFail();

    $this->actingAs($admin)
        ->from('/users?per_page=20&page=2')
        ->post("/users/{$target->id}/toggle-active")
        ->assertRedirect('/users?per_page=20&page=2');
});
