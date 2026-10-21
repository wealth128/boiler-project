<?php

use App\Models\AuditLog;
use App\Models\ImportBatch;
use App\Models\Setting;
use App\Models\Visit;
use Illuminate\Support\Facades\Storage;
use Tests\Support\ImportFile;
use Tests\Support\SecurityWorld;
use Tests\Support\Tamper;

/*
 * Step 7a, check 2 (Hospital settings, Backups, Import): wrong types, too
 * much text, values not in the allowed lists, extra fields, someone else's
 * file, a file that is not what its name says. Expected: 422 with a message,
 * 403 or 404, or the existing refusal message. Never a 500, and nothing
 * changes. Picture uploads are in FilesAndUploadsTest.
 */

afterEach(function () {
    ($this->world ?? null)?->cleanUp();
});

// --- Hospital settings ----------------------------------------------------------------

dataset('bad hospital forms', [
    'no hospital name' => [['hospital_name' => null], 'hospital_name'],
    'hospital name one character' => [['hospital_name' => 'A'], 'hospital_name'],
    'hospital name 151 characters' => [['hospital_name' => str_repeat('h', 151)], 'hospital_name'],
    'hospital name a list' => [['hospital_name' => ['AFP']], 'hospital_name'],
    'hospital name an object' => [['hospital_name' => ['x' => 'y']], 'hospital_name'],
    'office line 151 characters' => [['report_office' => str_repeat('o', 151)], 'report_office'],
    'office line a list' => [['report_office' => ['x']], 'report_office'],
    'prepared by 101 characters' => [['prepared_by' => str_repeat('p', 101)], 'prepared_by'],
    'prepared by a list' => [['prepared_by' => ['x']], 'prepared_by'],
    'prepared by title 101 characters' => [['prepared_by_title' => str_repeat('t', 101)], 'prepared_by_title'],
    'noted by 101 characters' => [['noted_by' => str_repeat('n', 101)], 'noted_by'],
    'noted by title 101 characters' => [['noted_by_title' => str_repeat('t', 101)], 'noted_by_title'],
    'logo sent as text' => [['logo' => 'C:\\Windows\\win.ini'], 'logo'],
    'logo sent as a list' => [['logo' => ['a']], 'logo'],
    'signature sent as text' => [['prepared_signature' => '../../.env'], 'prepared_signature'],
    'noted signature sent as a number' => [['noted_signature' => 5], 'noted_signature'],
]);

it('refuses a tampered hospital settings form and changes nothing', function (array $tamper, string $field) {
    $this->world = $w = SecurityWorld::boot('admin');
    $this->actingAs($w->actor);

    Tamper::refused($this, 'PUT', '/settings/hospital', $tamper + ['hospital_name' => 'Valid Hospital'], 422, $field);
})->with('bad hospital forms');

it('saves only the six text settings: other keys sent along are ignored', function () {
    $this->world = $w = SecurityWorld::boot('admin');
    $this->actingAs($w->actor);

    $this->json('PUT', '/settings/hospital', [
        'hospital_name' => 'Valid Hospital',
        'logo_path' => '../../.env',
        'prepared_by_signature' => 'x.png',
        'backup_path' => 'C:/anywhere',
        'key' => 'hospital_name', 'value' => 'hacked',
        'app_debug' => true,
        'role' => 'admin',
    ])->assertRedirect();

    expect(Setting::where('key', 'hospital_name')->value('value'))->toBe('Valid Hospital')
        ->and(Setting::where('key', 'logo_path')->value('value'))->not->toBe('../../.env')
        ->and(Setting::where('key', 'prepared_by_signature')->value('value'))->not->toBe('x.png')
        ->and(Setting::whereIn('key', ['backup_path', 'app_debug', 'role'])->count())->toBe(0);
});

it('stores a hospital name with HTML as plain text and never as a file path', function () {
    $this->world = $w = SecurityWorld::boot('admin');
    $this->actingAs($w->actor);

    $this->json('PUT', '/settings/hospital', ['hospital_name' => '<img src=x onerror=alert(1)>', 'prepared_by' => "Line1\nLine2"])->assertRedirect();

    expect(Setting::where('key', 'hospital_name')->value('value'))->toBe('<img src=x onerror=alert(1)>');
});

// --- Backups --------------------------------------------------------------------------------

it('refuses a USB copy for a drive that is not a plain drive letter, with a message', function (mixed $drive) {
    $this->world = $w = SecurityWorld::boot('admin');
    $w->backupFakes();
    $w->goodBackup();
    $this->actingAs($w->actor);

    Tamper::refused($this, 'POST', '/backups/usb-copy', $drive === 'MISSING' ? [] : ['drive' => $drive], 422, 'drive');

    expect(glob($w->usbFolder.'/*'))->toBe([])
        ->and(AuditLog::where('action', 'like', 'backup.usb%')->count())->toBe(0);
})->with([
    'MISSING', '', 'ZZ:', 'E:\\', 'E:/..', '..', '../..', 'E:\\..\\..', '\\\\server\\share', 'E:; calc.exe', '/etc', 'C:\\Windows', [[['E:']]], 5,
]);

it('refuses a USB copy to a drive that is not on the list of removable drives, and copies nothing', function (string $drive) {
    $this->world = $w = SecurityWorld::boot('admin');
    $w->backupFakes();
    $w->goodBackup();
    $this->actingAs($w->actor);

    $before = SecurityWorld::state();
    $response = $this->json('POST', '/backups/usb-copy', ['drive' => $drive]);

    // The refusal is audited as a failed copy (result "failed"); nothing is copied or marked as done.
    $after = SecurityWorld::state();
    unset($before['audit'], $after['audit']);

    expect($response->getStatusCode())->toBeIn([302, 303, 403, 422])
        ->and(glob($w->usbFolder.'/*'))->toBe([])
        ->and(AuditLog::where('action', 'backup.usb_copy')->get()->pluck('changes.result')->unique()->all())->toBe(['failed'])
        ->and($after)->toBe($before);
})->with(['C', 'C:', 'D:', 'Z:', 'a', 'x:']);

it('turns away an odd mark type on the Backups page', function (string $type) {
    $this->world = $w = SecurityWorld::boot('admin');
    $this->actingAs($w->actor);

    Tamper::refused($this, 'POST', "/backups/marks/{$type}", [], 404);
})->with(['bogus', 'usb_copy', 'USB-COPY', 'usb-copy%00', '..%2F..%2Fx', 'test-restore/extra', 'restore']);

it('ignores extra fields on a backup mark', function () {
    $this->world = $w = SecurityWorld::boot('admin');
    $this->actingAs($w->actor);

    $this->json('POST', '/backups/marks/usb-copy', ['marked_by' => $w->otherUser->id, 'marked_at' => '2020-01-01', 'type' => 'test-restore'])->assertRedirect();

    $mark = App\Models\BackupMark::sole();

    expect($mark->marked_by)->toBe($w->actor->id)
        ->and($mark->type->value)->toBe('usb_copy')
        ->and($mark->marked_at->isToday())->toBeTrue();
});

// --- Import ---------------------------------------------------------------------------------

it('refuses a tampered import upload with a message and stores nothing', function (string $case) {
    $this->world = $w = SecurityWorld::boot('admin');
    $this->actingAs($w->actor);

    $data = match ($case) {
        'no file' => [],
        'file sent as text' => ['file' => '../../.env'],
        'file sent as a list' => ['file' => ['a', 'b']],
        'an .xls file' => ['file' => ImportFile::raw('x', 'old.xls')],
        'a php file' => ['file' => ImportFile::raw('<?php system($_GET["c"]);', 'shell.php')],
        'a php file named .xlsx.php' => ['file' => ImportFile::raw('<?php echo 1;', 'visits.xlsx.php')],
        'an exe named .csv.exe' => ['file' => ImportFile::raw('MZ', 'visits.csv.exe')],
        'an svg' => ['file' => ImportFile::raw('<svg xmlns="http://www.w3.org/2000/svg" onload="alert(1)"/>', 'a.svg')],
        'a file over 2 MB' => ['file' => ImportFile::raw(str_repeat('a,b,c'."\n", 500000), 'big.csv')],
    };

    $before = SecurityWorld::state();
    $response = $this->json('POST', '/records/import', $data);

    expect($response->getStatusCode())->toBe(422)
        ->and($response->json('message'))->toBeString()->not->toBeEmpty()
        ->and(Storage::disk('local')->allFiles('imports'))->toBe([])
        ->and(SecurityWorld::state())->toBe($before);
})->with(['no file', 'file sent as text', 'file sent as a list', 'an .xls file', 'a php file', 'a php file named .xlsx.php', 'an exe named .csv.exe', 'an svg', 'a file over 2 MB']);

it('says what is wrong with a file that is called .xlsx or .csv but is not', function (string $name, string $content) {
    $this->world = $w = SecurityWorld::boot('admin');
    $this->actingAs($w->actor);

    $response = $this->json('POST', '/records/import', ['file' => ImportFile::raw($content, $name)]);

    expect($response->getStatusCode())->toBe(422)
        ->and($response->json('errors.file.0'))->toBeString()
        ->and(Storage::disk('local')->allFiles('imports'))->toBe([])
        ->and(Visit::where('written_by', '!=', null)->count())->toBe(0);
})->with([
    'php code as xlsx' => ['visits.xlsx', '<?php echo 1;'],
    'text as xlsx' => ['visits.xlsx', 'not a zip file at all'],
    'html as csv' => ['visits.csv', '<html><body onload=alert(1)></body></html>'],
    'empty csv' => ['visits.csv', ''],
    'binary as csv' => ['visits.csv', random_bytes(64)],
    'a zip that is not a workbook' => ['visits.xlsx', "PK\x03\x04".str_repeat("\0", 40)],
]);

it('refuses a tampered import confirm and imports nothing', function (array $data) {
    $this->world = $w = SecurityWorld::boot('admin');
    $this->actingAs($w->actor);
    $token = $w->stagedToken($this);

    $data = array_map(fn ($value) => $value === '{token}' ? $token : $value, $data);

    $before = Visit::count();
    $response = $this->json('POST', '/records/import/confirm', $data);

    expect($response->getStatusCode())->toBeIn([302, 303, 422], json_encode($data).' gave '.$response->getStatusCode())
        ->and(Visit::count())->toBe($before);
})->with([
    'nothing' => [[]],
    'no token' => [['rows' => [2]]],
    'token a list' => [['token' => ['x'], 'rows' => [2]]],
    'token too short' => [['token' => 'abc', 'rows' => [2]]],
    'token with a path' => [['token' => '../../../../.env'.str_repeat('a', 30), 'rows' => [2]]],
    'token of a file that never existed' => [['token' => str_repeat('z', 40), 'rows' => [2]]],
    'no rows' => [['token' => '{token}']],
    'rows empty' => [['token' => '{token}', 'rows' => []]],
    'rows text' => [['token' => '{token}', 'rows' => 'all']],
    'rows with text inside' => [['token' => '{token}', 'rows' => ['a']]],
    'row 0' => [['token' => '{token}', 'rows' => [0]]],
    'row 1 (the header)' => [['token' => '{token}', 'rows' => [1]]],
    'a negative row' => [['token' => '{token}', 'rows' => [-5]]],
    'a row far past the file' => [['token' => '{token}', 'rows' => [999999]]],
    'a row too big' => [['token' => '{token}', 'rows' => [99999999999999999999]]],
    'rows a list of lists' => [['token' => '{token}', 'rows' => [[2]]]],
    'new patient rows text' => [['token' => '{token}', 'rows' => [2], 'new_patient_rows' => 'x']],
    'new patient rows with text inside' => [['token' => '{token}', 'rows' => [2], 'new_patient_rows' => ['x']]],
]);

it('keeps another Admin account from using a staged file it did not upload', function () {
    $this->world = $w = SecurityWorld::boot('admin');
    $token = $w->stagedToken($this);
    $this->assertNotSame(str_repeat('a', 40), $token);

    $intruder = userWithRole('admin');
    $this->actingAs($intruder);

    $before = SecurityWorld::state();
    $this->json('POST', '/records/import/confirm', ['token' => $token, 'rows' => [2]]);
    $this->json('POST', '/records/import/cancel', ['token' => $token]);
    $page = $this->get("/records/import?file={$token}");

    expect($page->getStatusCode())->toBeLessThan(500)
        ->and(Visit::where('written_by', 'Nurse A. Ramos')->count())->toBe(0)
        ->and(Storage::disk('local')->allFiles('imports'))->not->toBe([])
        ->and(SecurityWorld::state())->toBe($before);
});

it('answers a cancel with an odd token without a server error and keeps the staged file', function (mixed $token) {
    $this->world = $w = SecurityWorld::boot('admin');
    $this->actingAs($w->actor);
    $staged = $w->stagedToken($this);

    $response = $this->json('POST', '/records/import/cancel', $token === 'MISSING' ? [] : ['token' => $token]);

    expect($response->getStatusCode())->toBeLessThan(500)
        ->and(Storage::disk('local')->allFiles('imports'))->not->toBe([])
        ->and($staged)->not->toBe(str_repeat('a', 40));
})->with(['MISSING', '', [['a']], [['a' => 'b']], 'x', '../../x', str_repeat('q', 5000), 12345]);

it('answers odd import page addresses without a server error', function (string $query) {
    $this->world = $w = SecurityWorld::boot('admin');
    $this->actingAs($w->actor);

    expect($this->get('/records/import?'.$query)->getStatusCode())->toBeLessThan(500, $query);
})->with(['file[]=a', 'file[a]=b', 'file=../../.env', 'file='.'%00', 'file=abc', 'page[]=1', 'page=-1', 'file=&page=zzz']);

it('refuses to undo an import that does not exist or was already undone, and one in a closed month', function () {
    $this->world = $w = SecurityWorld::boot('admin');
    $this->actingAs($w->actor);

    Tamper::refused($this, 'POST', '/records/import/999999/undo', [], 404);
    Tamper::refused($this, 'POST', '/records/import/abc/undo', [], 404);
    Tamper::refused($this, 'POST', '/records/import/-1/undo', [], 404);
    Tamper::refused($this, 'POST', '/records/import/99999999999999999999/undo', [], 404);

    // Already undone: a message, and nothing moves.
    $w->batch->forceFill(['undone_at' => now(), 'undone_by' => $w->actor->id])->save();
    $before = SecurityWorld::state();
    $response = $this->json('POST', "/records/import/{$w->batch->id}/undo");

    expect($response->getStatusCode())->toBeIn([302, 403, 422])
        ->and(SecurityWorld::state())->toBe($before);

    // A visit of the import sits in a closed month: the import can't be undone.
    $batch = ImportBatch::create(['file_name' => 'b.csv', 'original_rows' => 1, 'imported_count' => 1, 'skipped_count' => 0, 'imported_by' => $w->actor->id, 'created_at' => now()]);
    $closed = $w->closedMonthVisit();
    $closed->forceFill(['import_batch_id' => $batch->id])->saveQuietly();

    $before = SecurityWorld::state();
    $response = $this->json('POST', "/records/import/{$batch->id}/undo");

    expect($response->getStatusCode())->toBeIn([302, 403, 422])
        ->and(Visit::whereKey($closed->id)->exists())->toBeTrue()
        ->and(SecurityWorld::state())->toBe($before);
});
