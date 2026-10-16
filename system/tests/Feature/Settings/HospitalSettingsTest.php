<?php

use App\Models\AuditLog;
use App\Models\Setting;
use App\Services\AuditActions;
use App\Services\HospitalSettings;
use App\Services\MonthService;
use App\Services\ReportSheetResolver;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;

/*
 * Hospital settings: GET and PUT /settings/hospital, DELETE and GET
 * /settings/hospital/logo (planning/database-schema.md settings table,
 * planning/security.md section 2). Admin only, except that the Viewer (CO)
 * may load the logo picture because the report shows it.
 */

beforeEach(function () {
    $this->travelTo(Carbon::parse('2026-10-15 10:00', 'Asia/Manila'));
    Storage::fake('local');

    $this->admin = userWithRole('admin');
});

/**
 * A full, valid form.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function hospitalForm(array $overrides = []): array
{
    return [
        'hospital_name' => 'AFP Medical Center',
        'report_office' => 'Records Office',
        'prepared_by' => 'Ana Reyes',
        'prepared_by_title' => 'Admission Clerk',
        'noted_by' => 'COL Juan Cruz',
        'noted_by_title' => 'Chief of Hospital',
        ...$overrides,
    ];
}

function saveHospital(object $test, array $data): TestResponse
{
    return $test->actingAs($test->admin)->from('/settings/hospital')->put('/settings/hospital', $data);
}

/**
 * A real uploaded file with the given content and name. Unlike the fake
 * files of Laravel, its type is read from the content, as for a browser
 * upload, so a renamed file is not taken for a picture.
 */
function realUpload(string $name, string $content): UploadedFile
{
    $path = tempnam(sys_get_temp_dir(), 'logo');
    file_put_contents($path, $content);

    return new UploadedFile($path, $name, null, null, true);
}

/** Setting rows by key. */
function setting(string $key): ?string
{
    return Setting::valueOf($key);
}

// --- Page and save --------------------------------------------------------------

it('shows the form with the saved values to the Admin', function () {
    saveHospital($this, hospitalForm())->assertRedirect('/settings/hospital');

    $this->actingAs($this->admin)->get('/settings/hospital')->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('settings/hospital')
            ->where('values.hospital_name', 'AFP Medical Center')
            ->where('values.report_office', 'Records Office')
            ->where('values.prepared_by', 'Ana Reyes')
            ->where('values.noted_by_title', 'Chief of Hospital')
            ->where('defaults.report_office', 'Admission / Administrative Office')
            ->where('defaults.noted_by_title', 'Commanding Officer')
            ->where('logo_url', null)
            ->where('limits.logo_max_kb', 1024)
            ->where('limits.logo_max_pixels', 1000));
});

it('saves all six values and says "Settings saved."', function () {
    saveHospital($this, hospitalForm())
        ->assertRedirect('/settings/hospital')
        ->assertSessionHasNoErrors()
        ->assertSessionHas('inertia.flash_data.toast', ['type' => 'success', 'message' => 'Settings saved.']);

    expect(setting('hospital_name'))->toBe('AFP Medical Center')
        ->and(setting('report_office'))->toBe('Records Office')
        ->and(setting('prepared_by'))->toBe('Ana Reyes')
        ->and(setting('prepared_by_title'))->toBe('Admission Clerk')
        ->and(setting('noted_by'))->toBe('COL Juan Cruz')
        ->and(setting('noted_by_title'))->toBe('Chief of Hospital');
});

it('trims spaces around every text', function () {
    saveHospital($this, hospitalForm([
        'hospital_name' => '   AFP Medical Center  ',
        'prepared_by' => "  Ana Reyes\t",
        'noted_by_title' => '  Chief  ',
    ]))->assertSessionHasNoErrors();

    expect(setting('hospital_name'))->toBe('AFP Medical Center')
        ->and(setting('prepared_by'))->toBe('Ana Reyes')
        ->and(setting('noted_by_title'))->toBe('Chief');
});

// --- Limits and defaults ----------------------------------------------------------

it('needs a hospital name of 2 to 150 characters', function () {
    saveHospital($this, hospitalForm(['hospital_name' => '']))->assertSessionHasErrors('hospital_name');
    saveHospital($this, hospitalForm(['hospital_name' => '   ']))->assertSessionHasErrors('hospital_name');
    saveHospital($this, hospitalForm(['hospital_name' => 'A']))->assertSessionHasErrors('hospital_name');
    saveHospital($this, hospitalForm(['hospital_name' => ' A ']))->assertSessionHasErrors('hospital_name');
    saveHospital($this, hospitalForm(['hospital_name' => str_repeat('A', 151)]))->assertSessionHasErrors('hospital_name');

    saveHospital($this, hospitalForm(['hospital_name' => 'AB']))->assertSessionHasNoErrors();
    saveHospital($this, hospitalForm(['hospital_name' => str_repeat('A', 150)]))->assertSessionHasNoErrors();

    expect(mb_strlen(setting('hospital_name')))->toBe(150);
});

it('limits the office line to 150 characters and the names and titles to 100', function (string $field, int $max) {
    saveHospital($this, hospitalForm([$field => str_repeat('A', $max + 1)]))->assertSessionHasErrors($field);
    saveHospital($this, hospitalForm([$field => str_repeat('A', $max)]))->assertSessionHasNoErrors();

    expect(mb_strlen(setting($field)))->toBe($max);
})->with([
    'office line' => ['report_office', 150],
    'prepared by name' => ['prepared_by', 100],
    'prepared by title' => ['prepared_by_title', 100],
    'noted by name' => ['noted_by', 100],
    'noted by title' => ['noted_by_title', 100],
]);

it('keeps the default when the office line or a title is left empty', function () {
    saveHospital($this, hospitalForm([
        'report_office' => '',
        'prepared_by_title' => '   ',
        'noted_by_title' => null,
    ]))->assertSessionHasNoErrors();

    expect(setting('report_office'))->toBe('Admission / Administrative Office')
        ->and(setting('prepared_by_title'))->toBe('Admission / Admin Office')
        ->and(setting('noted_by_title'))->toBe('Commanding Officer');
});

it('lets the signatory names stay empty', function () {
    saveHospital($this, hospitalForm(['prepared_by' => '', 'noted_by' => null]))->assertSessionHasNoErrors();

    expect(setting('prepared_by'))->toBe('')->and(setting('noted_by'))->toBe('');
});

it('uses the defaults everywhere when nothing is saved', function () {
    $values = app(HospitalSettings::class)->values();

    expect($values)->toBe([
        'hospital_name' => '[Hospital Name]',
        'report_office' => 'Admission / Administrative Office',
        'prepared_by' => '',
        'prepared_by_title' => 'Admission / Admin Office',
        'noted_by' => '',
        'noted_by_title' => 'Commanding Officer',
    ]);
});

// --- Reports use the values ----------------------------------------------------------

it('puts the saved values and the logo on the report screen', function () {
    saveHospital($this, hospitalForm(['logo' => UploadedFile::fake()->image('logo.png', 200, 100)]))->assertSessionHasNoErrors();

    $sheet = $this->actingAs($this->admin)->get('/reports')->assertOk()->inertiaProps('sheet');

    expect($sheet)->toMatchArray([
        'hospital_name' => 'AFP Medical Center',
        'office' => 'Records Office',
        'prepared_by' => 'Ana Reyes',
        'prepared_by_title' => 'Admission Clerk',
        'noted_by' => 'COL Juan Cruz',
        'noted_by_title' => 'Chief of Hospital',
    ])->and($sheet['logo_url'])->toStartWith('/settings/hospital/logo?v=');
});

it('has no logo on the report screen when none is set', function () {
    $sheet = $this->actingAs($this->admin)->get('/reports')->inertiaProps('sheet');

    expect($sheet['logo_url'])->toBeNull();
});

it('shows the saved values and the logo in the PDF', function () {
    saveHospital($this, hospitalForm(['logo' => UploadedFile::fake()->image('logo.png', 200, 100)]))->assertSessionHasNoErrors();

    $this->actingAs($this->admin)->get('/reports/export/pdf')->assertOk();

    $data = app(ReportSheetResolver::class)->resolve([]);
    $html = view('reports.pdf', [...$data, 'generatedAt' => now(), 'logoData' => app(HospitalSettings::class)->logoDataUri()])->render();

    expect($html)->toContain('AFP Medical Center · Records Office')
        ->toContain('Ana Reyes')->toContain('Prepared by · Admission Clerk')
        ->toContain('COL Juan Cruz')->toContain('Noted by · Chief of Hospital')
        ->toContain('<img class="logo" src="data:image/png;base64,');
});

it('shows no logo in the PDF when none is set', function () {
    $data = app(ReportSheetResolver::class)->resolve([]);
    $html = view('reports.pdf', [...$data, 'generatedAt' => now(), 'logoData' => app(HospitalSettings::class)->logoDataUri()])->render();

    expect($html)->not->toContain('<img');
});

it('lets the Viewer (CO) see the logo on Reports', function () {
    saveHospital($this, hospitalForm(['logo' => UploadedFile::fake()->image('logo.png', 200, 100)]));

    $sheet = $this->actingAs(userWithRole('viewer'))->get('/reports')->assertOk()->inertiaProps('sheet');

    $this->actingAs(userWithRole('viewer'))->get($sheet['logo_url'])->assertOk();
});

// --- Logo ---------------------------------------------------------------------------

it('accepts a PNG or JPG logo and keeps it outside public/', function (string $name, string $mime) {
    saveHospital($this, hospitalForm(['logo' => UploadedFile::fake()->image($name, 300, 200)]))
        ->assertSessionHasNoErrors();

    $file = setting('logo_path');

    expect($file)->toBeIn(['logo.png', 'logo.jpg'])
        ->and(Storage::disk('local')->exists($file))->toBeTrue()
        ->and(file_exists(public_path($file)))->toBeFalse()
        ->and(Storage::disk('local')->path($file))->not->toStartWith(public_path())
        ->and(Storage::disk('local')->path($file))->not->toStartWith(storage_path('app/public'));

    $this->actingAs($this->admin)->get('/settings/hospital/logo')->assertOk()
        ->assertHeader('Content-Type', $mime)
        ->assertHeader('X-Content-Type-Options', 'nosniff');
})->with([
    'png' => ['logo.png', 'image/png'],
    'jpg' => ['logo.jpg', 'image/jpeg'],
    'jpeg' => ['logo.jpeg', 'image/jpeg'],
]);

it('stores the logo under a fixed name, never the uploaded name', function () {
    saveHospital($this, hospitalForm(['logo' => UploadedFile::fake()->image('../../evil name.png', 100, 100)]))
        ->assertSessionHasNoErrors();

    expect(setting('logo_path'))->toBe('logo.png')
        ->and(Storage::disk('local')->allFiles())->toBe(['logo.png']);
});

it('replaces the old logo when a new one of another type is saved', function () {
    saveHospital($this, hospitalForm(['logo' => UploadedFile::fake()->image('a.png', 100, 100)]));
    saveHospital($this, hospitalForm(['logo' => UploadedFile::fake()->image('b.jpg', 100, 100)]));

    expect(setting('logo_path'))->toBe('logo.jpg')
        ->and(Storage::disk('local')->allFiles())->toBe(['logo.jpg']);
});

it('refuses an SVG logo', function () {
    $svg = realUpload('logo.svg', '<svg xmlns="http://www.w3.org/2000/svg" width="10" height="10"><script>alert(1)</script></svg>');

    saveHospital($this, hospitalForm(['logo' => $svg]))->assertSessionHasErrors('logo');

    expect(Storage::disk('local')->allFiles())->toBe([]);
});

it('refuses an SVG renamed to .png', function () {
    $svg = realUpload('logo.png', '<svg xmlns="http://www.w3.org/2000/svg" width="10" height="10"></svg>');

    saveHospital($this, hospitalForm(['logo' => $svg]))->assertSessionHasErrors('logo');
});

it('refuses a file that is not a picture even if it is named .png', function () {
    $fake = realUpload('logo.png', "<?php echo 'not an image';");

    saveHospital($this, hospitalForm(['logo' => $fake]))->assertSessionHasErrors('logo');

    expect(Storage::disk('local')->allFiles())->toBe([]);
});

it('refuses a logo over 1 MB and accepts one right at 1 MB', function () {
    saveHospital($this, hospitalForm(['logo' => UploadedFile::fake()->image('logo.png', 100, 100)->size(1025)]))
        ->assertSessionHasErrors('logo');

    saveHospital($this, hospitalForm(['logo' => UploadedFile::fake()->image('logo.png', 100, 100)->size(1024)]))
        ->assertSessionHasNoErrors();
});

it('refuses a logo over 1000 x 1000 pixels and accepts one of exactly that size', function () {
    saveHospital($this, hospitalForm(['logo' => UploadedFile::fake()->image('logo.png', 1001, 500)]))
        ->assertSessionHasErrors('logo');
    saveHospital($this, hospitalForm(['logo' => UploadedFile::fake()->image('logo.png', 500, 1001)]))
        ->assertSessionHasErrors('logo');

    saveHospital($this, hospitalForm(['logo' => UploadedFile::fake()->image('logo.png', 1000, 1000)]))
        ->assertSessionHasNoErrors();
});

it('saves nothing when the logo is refused', function () {
    saveHospital($this, hospitalForm(['hospital_name' => 'Should Not Save', 'logo' => UploadedFile::fake()->image('logo.png', 1500, 1500)]))
        ->assertSessionHasErrors('logo');

    expect(setting('hospital_name'))->not->toBe('Should Not Save');
});

it('keeps the current logo when a save has no new file', function () {
    saveHospital($this, hospitalForm(['logo' => UploadedFile::fake()->image('logo.png', 100, 100)]));
    saveHospital($this, hospitalForm(['hospital_name' => 'Renamed Hospital']));

    expect(setting('logo_path'))->toBe('logo.png')
        ->and(Storage::disk('local')->exists('logo.png'))->toBeTrue();
});

it('needs a signed-in user to load the logo and 404s when there is none', function () {
    $this->get('/settings/hospital/logo')->assertRedirect(route('login'));

    $this->actingAs($this->admin)->get('/settings/hospital/logo')->assertNotFound();
});

it('does not serve the logo from the public folder or the storage link', function () {
    saveHospital($this, hospitalForm(['logo' => UploadedFile::fake()->image('logo.png', 100, 100)]));

    foreach (['/logo.png', '/storage/logo.png', '/storage/private/logo.png'] as $uri) {
        expect($this->actingAs($this->admin)->get($uri)->status())->toBeIn([403, 404]);
    }

    expect(config('filesystems.disks.local.root'))->toBe(storage_path('app/private'))
        ->and(config('filesystems.disks.local.root'))->not->toStartWith(public_path());
});

it('removes the logo', function () {
    saveHospital($this, hospitalForm(['logo' => UploadedFile::fake()->image('logo.png', 100, 100)]));

    $this->actingAs($this->admin)->from('/settings/hospital')->delete('/settings/hospital/logo')
        ->assertRedirect('/settings/hospital');

    expect(setting('logo_path'))->toBe('')
        ->and(Storage::disk('local')->allFiles())->toBe([]);

    $this->actingAs($this->admin)->get('/settings/hospital/logo')->assertNotFound();
    $this->actingAs($this->admin)->get('/settings/hospital')->assertInertia(fn (Assert $page) => $page->where('logo_url', null));
    $this->actingAs($this->admin)->get('/reports')->assertInertia(fn (Assert $page) => $page->where('sheet.logo_url', null));
});

// --- Audit log ------------------------------------------------------------------------

it('writes settings.updated with old and new values of each changed field only', function () {
    saveHospital($this, hospitalForm())->assertSessionHasNoErrors();
    AuditLog::query()->delete();

    saveHospital($this, hospitalForm(['hospital_name' => 'New Name Hospital', 'noted_by' => 'BGEN Santos']))
        ->assertSessionHasNoErrors();

    $entries = AuditLog::query()->where('action', 'settings.updated')->get();

    expect($entries)->toHaveCount(1)
        ->and($entries[0]->user_id)->toBe($this->admin->id)
        ->and($entries[0]->changes)->toEqual([
            'label' => 'Hospital settings',
            'old' => ['hospital_name' => 'AFP Medical Center', 'noted_by' => 'COL Juan Cruz'],
            'new' => ['hospital_name' => 'New Name Hospital', 'noted_by' => 'BGEN Santos'],
        ]);
});

it('writes old to new from the defaults the first time', function () {
    saveHospital($this, hospitalForm(['report_office' => '', 'prepared_by_title' => '', 'noted_by_title' => '']));

    $entry = AuditLog::query()->where('action', 'settings.updated')->sole();

    // MySQL JSON does not keep the key order, so compare without the order.
    expect($entry->changes['old'])->toEqual(['hospital_name' => '[Hospital Name]', 'prepared_by' => '', 'noted_by' => ''])
        ->and($entry->changes['new'])->toEqual(['hospital_name' => 'AFP Medical Center', 'prepared_by' => 'Ana Reyes', 'noted_by' => 'COL Juan Cruz']);
});

it('writes no settings.updated entry when nothing changed', function () {
    saveHospital($this, hospitalForm());
    AuditLog::query()->delete();

    saveHospital($this, hospitalForm());

    expect(AuditLog::query()->count())->toBe(0);
});

it('writes settings.logo_changed and settings.logo_removed without any picture data', function () {
    saveHospital($this, hospitalForm(['logo' => UploadedFile::fake()->image('logo.png', 100, 100)]));

    $changed = AuditLog::query()->where('action', 'settings.logo_changed')->sole();

    expect($changed->user_id)->toBe($this->admin->id)
        ->and($changed->changes)->toHaveKeys(['label', 'file_type', 'size_kb'])
        ->and($changed->changes['file_type'])->toBe('PNG')
        ->and(json_encode($changed->changes))->not->toContain('base64')->not->toContain('PNG'."\r\n");

    $this->actingAs($this->admin)->delete('/settings/hospital/logo');

    $removed = AuditLog::query()->where('action', 'settings.logo_removed')->sole();

    expect($removed->changes)->toBe(['label' => 'Hospital settings']);

    // Removing again (nothing to remove) adds nothing.
    $this->actingAs($this->admin)->delete('/settings/hospital/logo');

    expect(AuditLog::query()->where('action', 'settings.logo_removed')->count())->toBe(1);
});

it('has plain-language labels for the settings audit codes', function () {
    expect(AuditActions::label('settings.updated'))->toBe('Hospital settings saved')
        ->and(AuditActions::label('settings.logo_changed'))->toBe('Hospital logo changed')
        ->and(AuditActions::label('settings.logo_removed'))->toBe('Hospital logo removed');
});

it('shows the old to new values on the Audit log screen', function () {
    saveHospital($this, hospitalForm());

    $row = $this->actingAs($this->admin)->get('/audit-log')->assertOk()->inertiaProps('entries.data.0');

    expect($row['action_label'])->toBe('Hospital settings saved')
        ->and($row['record'])->toBe('Hospital settings')
        ->and(collect($row['details'])->firstWhere('field', 'Hospital name'))
        ->toMatchArray(['old' => '[Hospital Name]', 'new' => 'AFP Medical Center']);
});

// --- Who may ---------------------------------------------------------------------------

it('gives encoder, viewer and system admin a 403 on the page, the save and the remove', function (string $role) {
    $user = userWithRole($role);

    $this->actingAs($user)->get('/settings/hospital')->assertForbidden();
    $this->actingAs($user)->put('/settings/hospital', hospitalForm(['hospital_name' => 'Hacked']))->assertForbidden();
    $this->actingAs($user)->delete('/settings/hospital/logo')->assertForbidden();

    expect(setting('hospital_name'))->not->toBe('Hacked');
})->with(['encoder', 'viewer', 'system_admin']);

it('gives encoder and system admin a 403 on the logo picture', function (string $role) {
    saveHospital($this, hospitalForm(['logo' => UploadedFile::fake()->image('logo.png', 100, 100)]));

    $this->actingAs(userWithRole($role))->get('/settings/hospital/logo')->assertForbidden();
})->with(['encoder', 'system_admin']);

it('sends a signed-out visitor to the login page for every route', function () {
    $this->get('/settings/hospital')->assertRedirect(route('login'));
    $this->put('/settings/hospital', hospitalForm())->assertRedirect(route('login'));
    $this->delete('/settings/hospital/logo')->assertRedirect(route('login'));
    $this->get('/settings/hospital/logo')->assertRedirect(route('login'));
});

it('shows the menu item only to the Admin', function () {
    $source = file_get_contents(resource_path('js/layouts/app/nav-items.ts'));

    expect($source)->toContain("permission: 'manage-settings'");
});

it('shows a friendly "file too large" page when the whole upload is over the server limit', function () {
    $this->actingAs($this->admin)
        ->withHeaders(['X-Inertia' => 'true'])
        ->call('PUT', '/settings/hospital', [], [], [], ['CONTENT_LENGTH' => 9 * 1024 * 1024, 'CONTENT_TYPE' => 'multipart/form-data; boundary=x'])
        ->assertStatus(413)
        ->assertInertia(fn (Assert $page) => $page
            ->component('errors/error')
            ->where('status', 413)
            ->where('message', 'The file is too large. A picture must be 1 MB or smaller.'));
});

// --- Signature pictures ---------------------------------------------------------------

it('saves a signature picture for each signatory, outside public/', function (string $field, string $file) {
    saveHospital($this, hospitalForm([$field => UploadedFile::fake()->image('sig.png', 300, 100)]))
        ->assertSessionHasNoErrors();

    expect(Storage::disk('local')->exists($file))->toBeTrue()
        ->and(file_exists(public_path($file)))->toBeFalse();

    $who = str_replace('_signature', '', $field);

    $this->actingAs($this->admin)->get("/settings/hospital/signature/{$who}")->assertOk()
        ->assertHeader('Content-Type', 'image/png')
        ->assertHeader('X-Content-Type-Options', 'nosniff');
})->with([
    'prepared by' => ['prepared_signature', 'signature-prepared.png'],
    'noted by' => ['noted_signature', 'signature-noted.png'],
]);

it('uses the same limits for signatures as for the logo', function (string $field) {
    saveHospital($this, hospitalForm([$field => realUpload('s.svg', '<svg xmlns="http://www.w3.org/2000/svg"></svg>')]))->assertSessionHasErrors($field);
    saveHospital($this, hospitalForm([$field => realUpload('s.png', '<?php echo 1;')]))->assertSessionHasErrors($field);
    saveHospital($this, hospitalForm([$field => UploadedFile::fake()->image('s.png', 100, 100)->size(1025)]))->assertSessionHasErrors($field);
    saveHospital($this, hospitalForm([$field => UploadedFile::fake()->image('s.png', 1001, 100)]))->assertSessionHasErrors($field);

    expect(Storage::disk('local')->allFiles())->toBe([]);
})->with(['prepared_signature', 'noted_signature']);

it('puts the signature pictures on the report screen and in the PDF', function () {
    saveHospital($this, hospitalForm([
        'prepared_signature' => UploadedFile::fake()->image('a.png', 200, 80),
        'noted_signature' => UploadedFile::fake()->image('b.jpg', 200, 80),
    ]))->assertSessionHasNoErrors();

    // Signature pictures show only on a final report: September is the default month.
    app(MonthService::class)->close('2026-09', $this->admin);

    $sheet = $this->actingAs($this->admin)->get('/reports')->inertiaProps('sheet');

    expect($sheet['prepared_signature_url'])->toStartWith('/reports/signatures/2026-09/prepared?v=')
        ->and($sheet['noted_signature_url'])->toStartWith('/reports/signatures/2026-09/noted?v=');

    $this->actingAs($this->admin)->get('/reports/export/pdf')->assertOk();

    $hospital = app(HospitalSettings::class);
    $data = app(ReportSheetResolver::class)->resolve([]);
    $html = view('reports.pdf', [...$data, 'generatedAt' => now(), 'logoData' => null, 'signatureData' => [
        'prepared' => $hospital->imageDataUri('prepared_signature'),
        'noted' => $hospital->imageDataUri('noted_signature'),
    ]])->render();

    expect($html)->toContain('<img src="data:image/png;base64,')
        ->toContain('<img src="data:image/jpeg;base64,');
});

it('shows no signature pictures when none are set', function () {
    $sheet = $this->actingAs($this->admin)->get('/reports')->inertiaProps('sheet');

    expect($sheet['prepared_signature_url'])->toBeNull()->and($sheet['noted_signature_url'])->toBeNull();
});

it('lets the Viewer (CO) load the signature pictures but not encoder or system admin', function () {
    saveHospital($this, hospitalForm(['noted_signature' => UploadedFile::fake()->image('b.png', 100, 50)]));

    $this->actingAs(userWithRole('viewer'))->get('/settings/hospital/signature/noted')->assertOk();
    $this->actingAs(userWithRole('encoder'))->get('/settings/hospital/signature/noted')->assertForbidden();
    $this->actingAs(userWithRole('system_admin'))->get('/settings/hospital/signature/noted')->assertForbidden();
    $this->actingAs($this->admin)->get('/settings/hospital/signature/prepared')->assertNotFound();
    $this->actingAs($this->admin)->get('/settings/hospital/signature/someone')->assertNotFound();
});

it('needs a signed-in user to load a signature picture', function () {
    $this->get('/settings/hospital/signature/noted')->assertRedirect(route('login'));
});

it('removes a signature picture and only that one', function () {
    saveHospital($this, hospitalForm([
        'prepared_signature' => UploadedFile::fake()->image('a.png', 100, 50),
        'noted_signature' => UploadedFile::fake()->image('b.png', 100, 50),
    ]));

    $this->actingAs($this->admin)->from('/settings/hospital')->delete('/settings/hospital/signature/prepared')
        ->assertRedirect('/settings/hospital');

    expect(Storage::disk('local')->allFiles())->toBe(['signature-noted.png'])
        ->and(setting('prepared_by_signature'))->toBe('')
        ->and(setting('noted_by_signature'))->toBe('signature-noted.png');
});

it('writes settings.signature_changed and settings.signature_removed without picture data', function () {
    saveHospital($this, hospitalForm(['prepared_signature' => UploadedFile::fake()->image('a.png', 100, 50)]));

    $changed = AuditLog::query()->where('action', 'settings.signature_changed')->sole();

    expect($changed->user_id)->toBe($this->admin->id)
        ->and($changed->changes)->toHaveKeys(['label', 'signature', 'file_type', 'size_kb'])
        ->and($changed->changes['signature'])->toBe('Prepared by')
        ->and($changed->changes['file_type'])->toBe('PNG')
        ->and(json_encode($changed->changes))->not->toContain('base64');

    $this->actingAs($this->admin)->delete('/settings/hospital/signature/prepared');

    expect(AuditLog::query()->where('action', 'settings.signature_removed')->sole()->changes)
        ->toEqual(['label' => 'Hospital settings', 'signature' => 'Prepared by']);

    // Nothing left to remove: no second entry.
    $this->actingAs($this->admin)->delete('/settings/hospital/signature/prepared');

    expect(AuditLog::query()->where('action', 'settings.signature_removed')->count())->toBe(1);
});

it('gives encoder, viewer and system admin a 403 when removing a signature', function (string $role) {
    $this->actingAs(userWithRole($role))->delete('/settings/hospital/signature/prepared')->assertForbidden();
})->with(['encoder', 'viewer', 'system_admin']);

// --- Cropped pictures and the server re-save ----------------------------------------------

/** A real PNG made with GD: 40 x 20, red, with the left half fully transparent. */
function transparentPng(): string
{
    $image = imagecreatetruecolor(40, 20);
    imagealphablending($image, false);
    imagesavealpha($image, true);
    imagefill($image, 0, 0, imagecolorallocatealpha($image, 255, 0, 0, 0));
    imagefilledrectangle($image, 0, 0, 19, 19, imagecolorallocatealpha($image, 0, 0, 0, 127));

    ob_start();
    imagepng($image);

    return (string) ob_get_clean();
}

it('accepts the sizes the crop dialog makes: 600 x 600 for the logo and 900 x 300 for a signature', function () {
    saveHospital($this, hospitalForm([
        'logo' => UploadedFile::fake()->image('logo.png', 600, 600),
        'prepared_signature' => UploadedFile::fake()->image('signature.png', 900, 300),
        'noted_signature' => UploadedFile::fake()->image('signature.png', 900, 300),
    ]))->assertSessionHasNoErrors();

    expect(Storage::disk('local')->allFiles())->toEqualCanonicalizing(['logo.png', 'signature-prepared.png', 'signature-noted.png']);
});

it('saves the picture again, so anything hidden after the image data is dropped', function () {
    $evil = transparentPng().'<?php echo "hidden"; ?>';

    saveHospital($this, hospitalForm(['logo' => realUpload('logo.png', $evil)]))->assertSessionHasNoErrors();

    $stored = Storage::disk('local')->get('logo.png');

    expect($stored)->not->toContain('<?php')->not->toContain('hidden')
        ->and(getimagesizefromstring($stored))->toMatchArray([0 => 40, 1 => 20]);
});

it('keeps transparency when a PNG is saved again', function () {
    saveHospital($this, hospitalForm(['prepared_signature' => realUpload('signature.png', transparentPng())]))
        ->assertSessionHasNoErrors();

    $image = imagecreatefromstring(Storage::disk('local')->get('signature-prepared.png'));

    // Left half clear (alpha 127 = fully transparent), right half solid red.
    expect(imagecolorsforindex($image, imagecolorat($image, 5, 10))['alpha'])->toBe(127)
        ->and(imagecolorsforindex($image, imagecolorat($image, 30, 10))['alpha'])->toBe(0);
});

it('keeps a JPG as a JPG when it is saved again', function () {
    saveHospital($this, hospitalForm(['logo' => UploadedFile::fake()->image('logo.jpg', 300, 300)]))->assertSessionHasNoErrors();

    expect(Storage::disk('local')->exists('logo.jpg'))->toBeTrue()
        ->and(getimagesizefromstring(Storage::disk('local')->get('logo.jpg'))[2])->toBe(IMAGETYPE_JPEG);
});
