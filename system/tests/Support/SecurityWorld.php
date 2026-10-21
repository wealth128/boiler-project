<?php

namespace Tests\Support;

use App\Enums\BackupStatus;
use App\Models\AgeBracket;
use App\Models\AuditLog;
use App\Models\BackupMark;
use App\Models\Diagnosis;
use App\Models\MonthClosure;
use App\Models\MonthDeletion;
use App\Models\ReportSnapshot;
use App\Models\Setting;
use App\Models\TestRestoreRun;
use App\Models\BackupRun;
use App\Models\ImportBatch;
use App\Models\Patient;
use App\Models\Rank;
use App\Models\User;
use App\Models\Visit;
use App\Services\AuditLogger;
use App\Services\BackupMonitor;
use App\Services\HospitalSettings;
use App\Services\MonthService;
use App\Services\Setup\BackupFileChecker;
use App\Services\Setup\MysqlAdmin;
use App\Services\UsbCopyService;
use Illuminate\Http\Client\Response as ClientResponse;
use Illuminate\Http\UploadedFile;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use ZipArchive;

require_once __DIR__.'/SetupFakes.php';

/**
 * The records every security test needs: one account per role, a visit
 * encoded today, a patient without visits, an account to act on, items in
 * Trash, and the fakes that keep the heavy actions (mysqldump, the test
 * restore, the USB copy) from touching the real machine.
 *
 * The clock stands at 2026-10-09 14:00 (Asia/Manila), so September 2026 has
 * ended and can be closed. Files go to a fake "local" disk, never to the real
 * storage/app/private.
 *
 * Everything that costs time (a closed month with pictures, a backup on
 * disk) is built only when a test asks for it.
 */
final class SecurityWorld
{
    /** Who sends the request. "must_change" and "deactivated" are Admin accounts, the strongest role. */
    public const ACTORS = ['guest', 'encoder', 'admin', 'system_admin', 'viewer', 'must_change', 'deactivated'];

    public const ROLE_ACTORS = ['encoder', 'admin', 'system_admin', 'viewer'];

    public ?User $actor = null;

    /** @var array<string, mixed> */
    public array $lists;

    public User $encoder;

    public Visit $visit;

    public Patient $patient;

    public User $otherUser;

    public User $lockedUser;

    public User $loginUser;

    public Rank $rank;

    public Rank $rankB;

    public AgeBracket $bracket;

    public ImportBatch $batch;

    public Visit $trashedVisit;

    public Patient $trashedPatient;

    public string $backupFolder = '';

    public string $usbFolder = '';

    private ?User $admin = null;

    private bool $signaturesClosed = false;

    private ?string $stagedToken = null;

    public static function boot(string $actorKey = 'guest'): self
    {
        Carbon::setTestNow(Carbon::parse('2026-10-09 14:00', 'Asia/Manila'));
        Storage::fake('local');

        $world = new self;
        $world->actor = match ($actorKey) {
            'guest' => null,
            'encoder', 'admin', 'system_admin', 'viewer' => userWithRole($actorKey),
            'must_change' => userWithRole('admin', ['must_change_password' => true]),
            'deactivated' => userWithRole('admin', ['is_active' => false]),
        };

        $world->lists = ImportScenario::lists();
        $world->encoder = $world->actor?->role->value === 'encoder' ? $world->actor : userWithRole('encoder');
        $world->visit = Visit::factory()->create([
            'encoded_by' => $world->encoder->id,
            'visited_at' => now(),
            'rank_id' => $world->lists['cpl']->id,
            'diagnosis_id' => $world->lists['hypertension']->id,
            'category' => 'ER',
        ]);
        $world->patient = Patient::factory()->create();
        $world->otherUser = userWithRole('encoder');
        $world->lockedUser = userWithRole('encoder', ['failed_attempts' => 5, 'locked_at' => now()]);
        $world->loginUser = userWithRole('encoder');
        $world->rank = $world->lists['cpl'];
        $world->rankB = $world->lists['sgt'];
        $world->bracket = AgeBracket::create(['min_age' => 0, 'max_age' => 17]);
        $world->batch = ImportBatch::create([
            'file_name' => 'a.csv', 'original_rows' => 0, 'imported_count' => 0, 'skipped_count' => 0,
            'imported_by' => $world->admin()->id, 'created_at' => now(),
        ]);
        // Created first and moved to Trash after, so the number (visit_no, patient_no) is filled in.
        $world->trashedVisit = Visit::factory()->create([
            'encoded_by' => $world->encoder->id,
            'visited_at' => now(),
            'rank_id' => $world->lists['cpl']->id,
            'diagnosis_id' => $world->lists['hypertension']->id,
        ]);
        $world->trashedVisit->forceFill(['deleted_at' => now(), 'deleted_by' => $world->admin()->id, 'delete_reason' => 'Duplicate entry'])->saveQuietly();
        $world->trashedPatient = Patient::factory()->create();
        $world->trashedPatient->forceFill(['deleted_at' => now(), 'deleted_by' => $world->admin()->id, 'delete_reason' => 'Duplicate patient'])->saveQuietly();

        return $world;
    }

    /** An Admin account that is not the actor (for records that "someone else" made). */
    public function admin(): User
    {
        if ($this->actor?->role->value === 'admin' && $this->actor->is_active && ! $this->actor->must_change_password) {
            return $this->actor;
        }

        return $this->admin ??= userWithRole('admin');
    }

    /** A filled-in Encode form for a new patient (valid). @param  array<string, mixed>  $over */
    public function visitForm(array $over = []): array
    {
        return [
            'patient_id' => null,
            'last_name' => 'Dela Cruz',
            'first_name' => 'Juan',
            'middle_initial' => 'm.',
            'sex' => 'Male',
            'birthdate' => '1990-05-01',
            'age' => 36,
            'branch_id' => $this->lists['army']->id,
            'rank_id' => $this->lists['cpl']->id,
            'rank_other' => null,
            'diagnosis_id' => $this->lists['hypertension']->id,
            'diagnosis_other' => null,
            'category' => 'ER',
            'remarks' => 'From ER',
            ...$over,
        ];
    }

    /** The first delete reason of a type that is not "Other". @param  class-string<\BackedEnum>  $enum */
    public static function reason(string $enum): string
    {
        foreach ($enum::cases() as $case) {
            if (! str_starts_with((string) $case->value, 'Other')) {
                return (string) $case->value;
            }
        }

        throw new \LogicException("No plain reason in {$enum}");
    }

    /** A good user password (letters and numbers). */
    public static function newPassword(string $word = 'Fresh'): string
    {
        return $word.'Pass2026';
    }

    // --- Pictures and closed months -------------------------------------------

    public function withLogo(): void
    {
        app(HospitalSettings::class)->storeImage('logo', UploadedFile::fake()->image('logo.png', 200, 80));
    }

    public function withSignatures(): void
    {
        $settings = app(HospitalSettings::class);
        $settings->save(['prepared_by' => 'Ana Reyes', 'noted_by' => 'COL Cruz']);
        $settings->storeImage('prepared_signature', UploadedFile::fake()->image('p.png', 200, 80));
        $settings->storeImage('noted_signature', UploadedFile::fake()->image('n.png', 200, 80));
    }

    /** August 2026 closed by Admin, with the signature pictures frozen into its report. */
    public function closedAugust(): string
    {
        if (! $this->signaturesClosed) {
            $this->withSignatures();
            app(MonthService::class)->close('2026-08', $this->admin());
            $this->signaturesClosed = true;
        }

        return '2026-08';
    }

    /** A visit in September 2026 (an open month). */
    public function septemberVisit(): Visit
    {
        return Visit::factory()->create([
            'encoded_by' => $this->encoder->id,
            'visited_at' => Carbon::parse('2026-09-15 10:00', 'Asia/Manila'),
            'rank_id' => $this->lists['cpl']->id,
            'diagnosis_id' => $this->lists['hypertension']->id,
        ]);
    }

    /** A visit in a closed month (2026-08). */
    public function closedMonthVisit(): Visit
    {
        $visit = Visit::factory()->create([
            'encoded_by' => $this->encoder->id,
            'visited_at' => Carbon::parse('2026-08-10 10:00', 'Asia/Manila'),
            'rank_id' => $this->lists['cpl']->id,
            'diagnosis_id' => $this->lists['hypertension']->id,
        ]);

        $this->closedAugust();

        return $visit;
    }

    // --- Import ---------------------------------------------------------------

    /** The token of a file the Admin actor uploaded; a made-up one for anyone else. */
    public function stagedToken(?object $test = null): string
    {
        if ($this->stagedToken !== null) {
            return $this->stagedToken;
        }

        $dummy = str_repeat('a', 40);

        if ($test === null || $this->actor?->role->value !== 'admin' || ! $this->actor->is_active || $this->actor->must_change_password) {
            return $dummy;
        }

        $response = $test->actingAs($this->actor)->post('/records/import', ['file' => ImportFile::xlsx([ImportFile::row()])]);
        preg_match('/file=([A-Za-z0-9]{40})/', (string) $response->headers->get('Location'), $match);

        return $this->stagedToken = $match[1] ?? $dummy;
    }

    // --- Backups --------------------------------------------------------------

    /** A folder for backups and a fake "E:" drive; mysqldump and MySQL are replaced. */
    public function backupFakes(): void
    {
        $base = storage_path('framework/testing/security-'.uniqid());
        $this->backupFolder = $base.'/backups';
        $this->usbFolder = $base.'/E';
        File::ensureDirectoryExists($this->backupFolder);
        File::ensureDirectoryExists($this->usbFolder);

        config([
            'census.backup.path' => $this->backupFolder,
            'census.restore_test.password' => 'scratch-secret-pw',
        ]);

        // mysqldump: writes a small file and succeeds.
        Process::fake(function (PendingProcess $process) {
            $result = collect((array) $process->command)->first(fn ($a) => is_string($a) && str_starts_with($a, '--result-file='));

            if ($result) {
                file_put_contents(substr($result, strlen('--result-file=')), "-- MySQL dump\nCREATE TABLE visits (id int);\n-- Dump completed on x\n");
            }

            return Process::result(output: '', errorOutput: '', exitCode: 0);
        });

        // MySQL root account and the scratch database.
        $mysql = new FakeMysqlAdmin;
        $mysql->restoredCounts = ['patients' => 12, 'visits' => 40, 'users' => 4, 'closed_months' => 2];
        app()->instance(MysqlAdmin::class, $mysql);

        // The USB drive is a folder.
        $usb = $this->usbFolder;
        app()->bind(UsbCopyService::class, fn ($app) => new class($app->make(BackupMonitor::class), $app->make(BackupFileChecker::class), $app->make(AuditLogger::class), $usb) extends UsbCopyService
        {
            public function __construct(BackupMonitor $m, BackupFileChecker $c, AuditLogger $a, private string $usbFolder)
            {
                parent::__construct($m, $c, $a);
            }

            protected function rawDrives(): array
            {
                return [['DeviceID' => 'E:', 'VolumeName' => 'CENSUS-USB', 'Size' => 16 * 1024 ** 3, 'FreeSpace' => 8 * 1024 ** 3]];
            }

            protected function driveRoot(string $drive): string
            {
                return $this->usbFolder;
            }
        });
    }

    /** A complete backup on disk, with its run (for the test restore and the USB copy). */
    public function goodBackup(): BackupRun
    {
        $at = now()->subHour();
        $stamp = $at->format('Y-m-d_His');
        $zipName = "patient_census_files_{$stamp}.zip";

        file_put_contents($this->backupFolder."/patient_census_{$stamp}.sql", "-- MySQL dump\nCREATE TABLE visits (id int);\n-- Dump completed on 2026-10-09  1:00:00\n");

        $zip = new ZipArchive;
        $zip->open($this->backupFolder.'/'.$zipName, ZipArchive::CREATE);
        $zip->addFromString('backup-info.txt', 'info');
        $zip->close();

        return BackupRun::create([
            'started_at' => $at,
            'finished_at' => $at->copy()->addMinute(),
            'status' => BackupStatus::Success,
            'file_name' => "patient_census_{$stamp}.sql",
            'size_bytes' => 100,
            'zip_name' => $zipName,
            'zip_size_bytes' => 100,
            'patients_count' => 12,
            'visits_count' => 40,
            'users_count' => 4,
            'closed_months_count' => 2,
        ]);
    }

    /** Row counts and a few values, to prove a refused request changed nothing. @return array<string, mixed> */
    public static function state(): array
    {
        return [
            'visits' => Visit::withTrashed()->count(),
            'visits_trashed' => Visit::onlyTrashed()->count(),
            'patients' => Patient::withTrashed()->count(),
            'patients_trashed' => Patient::onlyTrashed()->count(),
            'users' => User::withTrashed()->count(),
            'users_trashed' => User::onlyTrashed()->count(),
            'users_hash' => User::withTrashed()->orderBy('id')->pluck('password', 'id')->all(),
            'users_flags' => User::withTrashed()->orderBy('id')->get(['id', 'is_active', 'locked_at', 'role', 'must_change_password'])->toArray(),
            'ranks' => Rank::withTrashed()->orderBy('id')->get(['id', 'name', 'sort_order', 'deleted_at'])->toArray(),
            'visit_rows' => Visit::withTrashed()->orderBy('id')->get()->toArray(),
            'patient_rows' => Patient::withTrashed()->orderBy('id')->get()->toArray(),
            'diagnoses' => Diagnosis::withTrashed()->orderBy('id')->get()->toArray(),
            'age_brackets' => AgeBracket::withTrashed()->orderBy('id')->get()->toArray(),
            'closures' => MonthClosure::count(),
            'snapshots' => ReportSnapshot::count(),
            'month_deletions' => MonthDeletion::count(),
            'settings' => Setting::orderBy('key')->pluck('value', 'key')->all(),
            'backups' => BackupRun::count() + BackupMark::count() + TestRestoreRun::count(),
            'audit' => AuditLog::where('action', '!=', 'user.session_ended')->count(),
        ];
    }

    public function cleanUp(): void
    {
        if ($this->backupFolder !== '') {
            File::deleteDirectory(dirname($this->backupFolder));
        }
    }
}
