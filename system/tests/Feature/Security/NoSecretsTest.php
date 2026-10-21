<?php

use App\Models\AuditLog;
use App\Models\BackupRun;
use App\Models\User;
use App\Services\RedactSecretsInLogs;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Tests\Support\SecurityWorld;

/*
 * Step 7a, check 3 (second part): no audit entry, log line or page ever
 * contains a password, a password hash, a remember_token, the APP_KEY, the
 * database password or the restore-test password. The test does real work
 * with known secrets (new account, reset, change, wrong logins, a failed
 * backup that repeats the secrets, a database error with a hash in it), then
 * searches everything that was written or shown.
 */

afterEach(function () {
    ($this->world ?? null)?->cleanUp();

    if (isset($this->logFile) && is_file($this->logFile)) {
        @unlink($this->logFile);
    }
});

const SEC_SECRET_TYPED = ['TypedSecret2026a', 'ResetSecret2026b', 'ChangedSecret2026c', 'WrongSecret2026d', 'ChosenSecret2026e'];
const SEC_SECRET_DB = 'DbSecret-7Hq88m';
const SEC_SECRET_RESTORE = 'RestoreSecret-9Zx41q';

/** Every secret that must never appear. @return array<string, string> */
function secSecretsNow(): array
{
    $secrets = [];

    foreach (SEC_SECRET_TYPED as $i => $typed) {
        $secrets["typed password {$i}"] = $typed;
    }

    $secrets['database password'] = SEC_SECRET_DB;
    $secrets['restore-test password'] = SEC_SECRET_RESTORE;
    $secrets['APP_KEY'] = (string) config('app.key');
    $secrets['APP_KEY body'] = str_replace('base64:', '', (string) config('app.key'));

    foreach (User::withTrashed()->get(['id', 'password', 'remember_token']) as $user) {
        $secrets["hash of user {$user->id}"] = (string) $user->password;

        if ((string) $user->remember_token !== '') {
            $secrets["remember_token of user {$user->id}"] = (string) $user->remember_token;
        }
    }

    return array_filter($secrets, fn (string $value): bool => strlen($value) >= 8);
}

function secAssertNoSecrets(string $haystack, string $where): void
{
    foreach (secSecretsNow() as $name => $value) {
        expect(str_contains($haystack, $value))->toBeFalse("{$where} contains the {$name}");
    }

    expect(preg_match('/\$2[abxy]\$\d{2}\$[.\/A-Za-z0-9]{53}/', $haystack))->toBe(0, "{$where} contains a bcrypt hash");
}

it('keeps every secret out of the audit log, the log file and every page', function () {
    $this->world = $w = SecurityWorld::boot('admin');
    $w->backupFakes();

    // Known secrets in the settings the app really reads them from.
    config([
        'database.connections.mysql.password' => SEC_SECRET_DB,
        'census.restore_test.password' => SEC_SECRET_RESTORE,
    ]);

    // Log to a file of this test only.
    $this->logFile = storage_path('framework/testing/security-'.uniqid().'.log');
    config(['logging.default' => 'single', 'logging.channels.single.path' => $this->logFile]);
    Log::forgetChannel('single');

    $this->actingAs($w->actor);

    // 1. Passwords typed in forms: new account, reset, own change, choose-new.
    $this->post('/users', ['name' => 'Typed One', 'username' => 'typed.one', 'role' => 'encoder', 'office' => 'ER', 'password' => SEC_SECRET_TYPED[0], 'password_confirmation' => SEC_SECRET_TYPED[0]])->assertRedirect();
    $this->post("/users/{$w->otherUser->id}/reset-password", ['password' => SEC_SECRET_TYPED[1], 'password_confirmation' => SEC_SECRET_TYPED[1], 'own_password' => 'password'])->assertRedirect();
    $this->put('/settings/password', ['current_password' => 'password', 'password' => SEC_SECRET_TYPED[2], 'password_confirmation' => SEC_SECRET_TYPED[2]])->assertRedirect();
    $this->post('/users', ['name' => 'Bad One', 'username' => 'bad.one', 'role' => 'encoder', 'office' => 'ER', 'password' => SEC_SECRET_TYPED[3], 'password_confirmation' => 'does-not-match'])->assertSessionHasErrors('password');

    // 2. Wrong logins with a typed password, until the account locks.
    auth()->logout();
    foreach (range(1, 5) as $i) {
        $this->post('/login', ['username' => $w->loginUser->username, 'password' => SEC_SECRET_TYPED[3]]);
    }

    // 3. A backup that fails and repeats every secret in its error text.
    $this->actingAs($w->actor);
    Process::fake(fn (PendingProcess $process) => Process::result(
        output: '',
        errorOutput: "mysqldump: Got error: 1045: Access denied for user 'census_app'@'localhost' (using password: YES) password=".SEC_SECRET_DB.' restore='.SEC_SECRET_RESTORE.' key='.config('app.key').' hash='.Hash::make('x'),
        exitCode: 2,
    ));
    $this->post('/backups/run')->assertRedirect();

    // 4. A database error whose message holds the values, reported like any unhandled error.
    try {
        DB::table('users')->insert([
            'name' => 'Dup', 'username' => $w->actor->username, 'password' => Hash::make(SEC_SECRET_TYPED[4]),
            'role' => 'encoder', 'office' => 'ER', 'remember_token' => 'remember-token-abcdef123456',
        ]);
    } catch (Throwable $e) {
        report($e);
    }

    // 5. A line that logs a secret by mistake.
    Log::error('Oops: password='.SEC_SECRET_DB.' and key '.config('app.key'));

    // --- The audit log, as stored.
    $audit = AuditLog::all()->map(fn (AuditLog $entry) => json_encode($entry->toArray()))->implode("\n");
    expect($audit)->not->toBe('');
    secAssertNoSecrets($audit, 'the audit log');

    // --- The backup record, as stored.
    secAssertNoSecrets((string) BackupRun::query()->latest('id')->value('message'), 'the stored backup error');

    // --- The log file.
    clearstatcache();
    $log = (string) @file_get_contents($this->logFile);
    expect($log)->toContain('Backup failed')->toContain(RedactSecretsInLogs::HIDDEN);
    secAssertNoSecrets($log, 'the log file');

    // --- Every page, for every role that can open it (the whole page, so the shared props too).
    $pages = [
        'admin' => ['/users', '/backups', '/audit-log', '/trash', '/system/health', '/settings/hospital', '/settings/lists', '/records/visits', '/records/patients', '/records/import', '/reports', '/encode', '/settings/profile', '/settings/security'],
        'system_admin' => ['/users', '/settings/profile'],
        'viewer' => ['/reports', '/settings/profile'],
        'encoder' => ['/encode', '/settings/profile'],
    ];

    foreach ($pages as $role => $urls) {
        $user = $role === 'admin' ? $w->actor : userWithRole($role);

        foreach ($urls as $url) {
            $this->actingAs($user);
            $response = $this->get($url);

            expect($response->getStatusCode())->toBeLessThan(400, "{$role} {$url}");
            secAssertNoSecrets((string) $response->getContent(), "{$role} {$url}");

            // And the same page asked for by the page loader (Inertia JSON).
            $json = $this->get($url, ['X-Inertia' => 'true', 'X-Requested-With' => 'XMLHttpRequest', 'X-Inertia-Version' => inertia()->getVersion()]);
            secAssertNoSecrets((string) $json->getContent(), "{$role} {$url} (Inertia)");
        }
    }
});

it('shares only the id, name, username, role, office and sign-in value with the page', function () {
    $this->world = $w = SecurityWorld::boot('encoder');

    $props = $this->actingAs($w->actor)->get('/encode')->inertiaProps();

    expect(array_keys($props['auth']['user']))->toBe(['id', 'name', 'username', 'role', 'office'])
        ->and(array_keys($props['auth']))->toBe(['user', 'loginAt'])
        ->and(json_encode($props))->not->toContain('password')->not->toContain('remember_token');
});

it('cleans a log line: secrets from the settings, bcrypt hashes and "name = value" pairs', function () {
    config(['database.connections.mysql.password' => SEC_SECRET_DB, 'census.restore_test.password' => SEC_SECRET_RESTORE]);

    $hash = Hash::make('anything');
    $line = "key ".config('app.key')." db ".SEC_SECRET_DB." restore ".SEC_SECRET_RESTORE." hash {$hash} update `users` set `remember_token` = 'abcdef' where `password` = 'plain text'";

    $clean = RedactSecretsInLogs::redact($line);

    expect($clean)->not->toContain(SEC_SECRET_DB)->not->toContain(SEC_SECRET_RESTORE)->not->toContain($hash)
        ->not->toContain((string) config('app.key'))->not->toContain('abcdef')->not->toContain('plain text')
        ->toContain('update `users` set');
});

it('puts the log cleaner on every file channel', function () {
    foreach (['single', 'daily', 'monthly', 'errorlog'] as $channel) {
        expect(in_array(RedactSecretsInLogs::class, (array) config("logging.channels.{$channel}.tap"), true))->toBeTrue("{$channel} has no log cleaner");
    }
});
