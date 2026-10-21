<?php

use App\Models\ImportBatch;
use Illuminate\Support\Facades\Storage;
use Tests\Support\ImportFile;
use Tests\Support\ImportScenario;

/*
 * Who may use the downtime import (planning/rbac.md): Admin only. Encoder,
 * Viewer (CO) and System Admin get the 403 page on every import route, and
 * the import routes are limited to 10 a minute. The full role table for all
 * routes is in tests/Feature/Rbac/RoleAccessTest.php.
 */

beforeEach(function () {
    Storage::fake('local');
    ImportScenario::lists();
});

it('gives 403 to every other role on every import route', function (string $role) {
    $actor = userWithRole($role);
    $batch = ImportBatch::create(['file_name' => 'a.csv', 'original_rows' => 0, 'imported_count' => 0, 'skipped_count' => 0, 'imported_by' => userWithRole('admin')->id, 'created_at' => now()]);

    $this->actingAs($actor);

    $this->get('/records/import')->assertForbidden();
    $this->get('/records/import?file='.str_repeat('a', 40))->assertForbidden();
    $this->get('/records/import/template')->assertForbidden();
    $this->post('/records/import', ['file' => ImportFile::csv([ImportFile::row()])])->assertForbidden();
    $this->post('/records/import/confirm', ['token' => str_repeat('a', 40), 'rows' => [2]])->assertForbidden();
    $this->post('/records/import/cancel', ['token' => str_repeat('a', 40)])->assertForbidden();
    $this->post("/records/import/{$batch->id}/undo")->assertForbidden();

    expect(Storage::disk('local')->allFiles('imports'))->toBe([])
        ->and($batch->fresh()->undone_at)->toBeNull();
})->with(['encoder', 'viewer', 'system_admin']);

it('limits the import routes to 10 a minute for each user', function () {
    $admin = userWithRole('admin');

    foreach (range(1, 10) as $i) {
        $this->actingAs($admin)->get('/records/import/template')->assertOk();
    }

    $this->actingAs($admin)->get('/records/import/template')->assertStatus(429);
    $this->actingAs($admin)->post('/records/import', [])->assertStatus(429);

    // The page has its own limit: the 10 above did not use it up.
    $this->actingAs($admin)->get('/records/import')->assertOk();

    $this->travel(61)->seconds();

    $this->actingAs($admin)->get('/records/import/template')->assertOk();
});

it('shows the Import menu entry data to Admin only', function () {
    expect(Gate::forUser(userWithRole('admin'))->allows('import-visits'))->toBeTrue()
        ->and(Gate::forUser(userWithRole('encoder'))->allows('import-visits'))->toBeFalse();
});

it('limits the Import page and the paging of Past imports to 60 a minute for each user, apart from the 10 for actions', function () {
    $admin = userWithRole('admin');

    foreach (range(1, 60) as $i) {
        $this->actingAs($admin)->get('/records/import?page=1')->assertOk();
    }

    $this->actingAs($admin)->get('/records/import')->assertStatus(429);

    // The actions have their own limit, so they still work.
    $this->actingAs($admin)->get('/records/import/template')->assertOk();

    $this->travel(61)->seconds();

    $this->actingAs($admin)->get('/records/import')->assertOk();
});
