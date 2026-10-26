<?php

namespace Database\Seeders;

use App\Enums\Office;
use App\Enums\Role;
use App\Models\Patient;
use App\Models\User;
use App\Models\Visit;
use App\Observers\AuditObserver;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use RuntimeException;

/**
 * A busy year of data for measuring speed (docs/developer-guide.md,
 * "Performance"): about 60 ER visits a day for a year, 15,000 patients,
 * 100,000 audit entries within the last year and 300 backup runs.
 *
 * Runs ONLY on a database whose name ends in "_test" (patient_census_test),
 * never on the real or the dev database, and only on an empty one:
 *
 *   DB_DATABASE=patient_census_test php artisan migrate:fresh
 *   DB_DATABASE=patient_census_test PERF_PASSWORD=... php artisan db:seed --class=PerformanceSeeder
 *
 * Accounts: perf.admin, perf.encoder1-4, perf.viewer and perf.sysadmin, all with
 * the password in PERF_PASSWORD (never written in a file). The same random seed
 * gives the same data every time, so measurements before and after a change compare.
 */
class PerformanceSeeder extends Seeder
{
    public const PATIENTS = 15000;

    public const VISITS_PER_DAY = 60;

    public const AUDIT_ENTRIES = 100000;

    public const BACKUP_RUNS = 300;

    private const LAST_NAMES = ['Santos', 'Reyes', 'Cruz', 'Bautista', 'Ocampo', 'Garcia', 'Mendoza', 'Torres', 'Tomas', 'Andrada', 'Castillo', 'Flores', 'Villanueva', 'Ramos', 'Castro', 'Rivera', 'Aquino', 'Navarro', 'Salazar', 'Mercado', 'Dela Cruz', 'De Leon', 'Gonzales', 'Lopez', 'Aguilar', 'Pascual', 'Valdez', 'Domingo', 'Gutierrez', 'Soriano', 'Fernandez', 'Manalo', 'Hernandez', 'Morales', 'Dizon', 'Santiago', 'Javier', 'Perez', 'Lim', 'Tan', 'Sison', 'Marquez', 'Buenaventura', 'Caballero', 'Del Rosario', 'Estrada', 'Galang', 'Ilagan', 'Lacson', 'Macaraeg', 'Nepomuceno', 'Ortega', 'Panganiban', 'Quiambao', 'Roxas', 'Samonte', 'Tolentino', 'Umali', 'Velasco', 'Yap', 'Zamora', 'Abad', 'Bernardo', 'Cabrera', 'Dimaculangan', 'Enriquez', 'Francisco', 'Gatchalian', 'Hidalgo', 'Ignacio'];

    private const FIRST_NAMES = ['Juan', 'Jose', 'Maria', 'Ana', 'Mark', 'John', 'Michael', 'Angelo', 'Christian', 'Paolo', 'Ramon', 'Rogelio', 'Eduardo', 'Ferdinand', 'Antonio', 'Carlo', 'Daniel', 'Gabriel', 'Joshua', 'Kristine', 'Liza', 'Marites', 'Nenita', 'Rosario', 'Teresita', 'Aileen', 'Bea', 'Camille', 'Divina', 'Elena', 'Fatima', 'Grace', 'Hazel', 'Irene', 'Jasmine', 'Karen', 'Lourdes', 'Monica', 'Noel', 'Oscar', 'Pedro', 'Quirino', 'Rafael', 'Samuel', 'Teodoro', 'Ulysses', 'Victor', 'Wilfredo', 'Xavier', 'Yolanda', 'Zenaida', 'Arnel', 'Benjie', 'Cesar', 'Dante', 'Ernesto', 'Felipe', 'Gerardo', 'Hector', 'Isidro'];

    public function run(): void
    {
        $database = DB::connection()->getDatabaseName();

        if (! str_ends_with((string) $database, '_test')) {
            throw new RuntimeException("PerformanceSeeder only runs on a *_test database, not \"{$database}\".");
        }

        if (DB::table('patients')->exists() || DB::table('visits')->exists()) {
            throw new RuntimeException('PerformanceSeeder needs an empty database: run migrate:fresh first.');
        }

        $password = env('PERF_PASSWORD');

        if (! is_string($password) || strlen($password) < 8) {
            throw new RuntimeException('Set PERF_PASSWORD (8 characters or more) for the perf.* accounts.');
        }

        mt_srand(20261026);

        AuditObserver::muted(function () use ($password): void {
            $this->call([BranchAndRankSeeder::class, DiagnosisSeeder::class, AgeBracketSeeder::class, SettingSeeder::class]);
            $users = $this->users($password);
            $this->patients();
            $visitIds = $this->visits($users['encoders'], $users['admin']);
            $this->audit($users, $visitIds);
            $this->backups();
        });

        DB::statement('ANALYZE TABLE visits, patients, audit_logs, backup_runs');
    }

    /** @return array{admin: int, encoders: list<int>, all: list<int>} */
    private function users(string $password): array
    {
        $hash = Hash::make($password);
        $make = fn (string $username, string $name, Role $role, Office $office): int => User::forceCreate([
            'name' => $name,
            'username' => $username,
            'password' => $hash,
            'role' => $role,
            'office' => $office,
            'is_active' => true,
            'must_change_password' => false,
        ])->id;

        $admin = $make('perf.admin', 'Perf Administrator', Role::Admin, Office::Admin);
        $encoders = [];

        for ($i = 1; $i <= 4; $i++) {
            $encoders[] = $make("perf.encoder{$i}", "Perf Encoder {$i}", Role::Encoder, Office::ER);
        }

        $viewer = $make('perf.viewer', 'Perf Viewer (CO)', Role::Viewer, Office::Command);
        $sysadmin = $make('perf.sysadmin', 'Perf System Admin', Role::SystemAdmin, Office::IT);

        return ['admin' => $admin, 'encoders' => $encoders, 'all' => [$admin, ...$encoders, $viewer, $sysadmin]];
    }

    private function patients(): void
    {
        $start = CarbonImmutable::now()->subDays(370);
        $rows = [];

        for ($id = 1; $id <= self::PATIENTS; $id++) {
            $created = $start->addMinutes(mt_rand(0, 365 * 24 * 60))->format('Y-m-d H:i:s');
            $rows[] = [
                'id' => $id,
                'patient_no' => Patient::formatNumber($id),
                'last_name' => self::LAST_NAMES[mt_rand(0, count(self::LAST_NAMES) - 1)],
                'first_name' => self::FIRST_NAMES[mt_rand(0, count(self::FIRST_NAMES) - 1)],
                'middle_initial' => mt_rand(0, 9) === 0 ? null : chr(mt_rand(65, 90)).'.',
                'sex' => mt_rand(0, 99) < 62 ? 'Male' : 'Female',
                'birthdate' => sprintf('%04d-%02d-%02d', mt_rand(1945, 2022), mt_rand(1, 12), mt_rand(1, 28)),
                'created_at' => $created,
                'updated_at' => $created,
            ];

            if (count($rows) === 1000) {
                DB::table('patients')->insert($rows);
                $rows = [];
            }
        }

        if ($rows !== []) {
            DB::table('patients')->insert($rows);
        }

        // A few patients in Trash (with no visits that are not in Trash: none of them get visits below).
        DB::table('patients')->where('id', '>', self::PATIENTS - 20)->update([
            'deleted_at' => now()->subDays(3)->format('Y-m-d H:i:s'),
            'delete_reason' => 'Duplicate patient record',
        ]);
    }

    /**
     * @param  list<int>  $encoders
     * @return list<int>
     */
    private function visits(array $encoders, int $admin): array
    {
        $pairs = DB::table('ranks')->whereNull('deleted_at')->get(['id', 'branch_id', 'is_other'])->all();
        $diagnoses = DB::table('diagnoses')->whereNull('deleted_at')->get(['id', 'is_other'])->all();
        $birthdates = DB::table('patients')->pluck('birthdate', 'id')->all();
        $today = CarbonImmutable::today();
        $rows = [];
        $id = 0;

        for ($day = 364; $day >= 0; $day--) {
            $date = $today->subDays($day);
            $count = self::VISITS_PER_DAY + mt_rand(-10, 10);

            for ($n = 0; $n < $count; $n++) {
                $id++;
                $at = $date->addSeconds(mt_rand(0, 86399));

                if ($at->isFuture()) {
                    $at = $date;
                }

                $patient = mt_rand(1, self::PATIENTS - 20);
                $rank = $pairs[mt_rand(0, count($pairs) - 1)];
                $diagnosis = $diagnoses[mt_rand(0, count($diagnoses) - 1)];
                $roll = mt_rand(0, 99);
                $stamp = $at->format('Y-m-d H:i:s');
                $trashed = mt_rand(0, 199) === 0;

                $rows[] = [
                    'id' => $id,
                    'visit_no' => Visit::formatNumber($id),
                    'patient_id' => $patient,
                    'visited_at' => $stamp,
                    'age' => max(0, min(120, (int) floor(CarbonImmutable::parse($birthdates[$patient])->diffInYears($at, true)))),
                    'branch_id' => $rank->branch_id,
                    'rank_id' => $rank->id,
                    'rank_other' => $rank->is_other ? 'Civilian volunteer' : null,
                    'diagnosis_id' => $diagnosis->id,
                    'diagnosis_other' => $diagnosis->is_other ? 'Insect bite, left arm' : null,
                    'category' => $roll < 90 ? 'ER' : ($roll < 97 ? 'Admission' : 'OPD'),
                    'remarks' => mt_rand(0, 4) === 0 ? 'Walk-in, accompanied by relative' : null,
                    'encoded_by' => $encoders[mt_rand(0, count($encoders) - 1)],
                    'created_at' => $stamp,
                    'updated_at' => $stamp,
                    'deleted_at' => $trashed ? $stamp : null,
                    'deleted_by' => $trashed ? $admin : null,
                    'delete_reason' => $trashed ? 'Duplicate entry' : null,
                ];

                if (count($rows) === 1000) {
                    DB::table('visits')->insert($rows);
                    $rows = [];
                }
            }
        }

        if ($rows !== []) {
            DB::table('visits')->insert($rows);
        }

        return range(1, $id);
    }

    /**
     * @param  array{admin: int, encoders: list<int>, all: list<int>}  $users
     * @param  list<int>  $visitIds
     */
    private function audit(array $users, array $visitIds): void
    {
        $now = CarbonImmutable::now();
        $seconds = 364 * 86400;
        $rows = [];

        for ($i = 0; $i < self::AUDIT_ENTRIES; $i++) {
            $roll = mt_rand(0, 99);
            $user = $roll < 5 ? $users['admin'] : $users['all'][mt_rand(0, count($users['all']) - 1)];
            $changes = null;

            [$action, $type, $subject] = match (true) {
                $roll < 25 => ['visit.created', 'visits', $visitIds[mt_rand(0, count($visitIds) - 1)]],
                $roll < 30 => ['visit.updated', 'visits', $visitIds[mt_rand(0, count($visitIds) - 1)]],
                $roll < 40 => ['patient.created', 'patients', mt_rand(1, self::PATIENTS)],
                $roll < 60 => ['user.login', 'users', $user],
                $roll < 78 => ['user.logout', 'users', $user],
                $roll < 81 => ['user.login_failed', 'users', $user],
                $roll < 90 => ['report.exported', 'users', $user],
                $roll < 95 => ['user.session_ended', 'users', $user],
                default => ['patient.updated', 'patients', mt_rand(1, self::PATIENTS)],
            };

            if ($action === 'visit.updated') {
                $changes = json_encode(['old' => ['remarks' => null, 'age' => 30], 'new' => ['remarks' => 'Corrected age', 'age' => 31]]);
            } elseif ($action === 'report.exported') {
                $changes = json_encode(['format' => 'excel', 'period' => '2026-09', 'category' => 'All']);
            } elseif ($action === 'user.session_ended') {
                $changes = json_encode(['reason' => 'daily_cutoff', 'cutoff' => '20:00']);
            }

            $rows[] = [
                'user_id' => $user,
                'action' => $action,
                'subject_type' => $type,
                'subject_id' => $subject,
                'changes' => $changes,
                'ip_address' => '192.168.1.'.mt_rand(10, 60),
                'created_at' => $now->subSeconds(mt_rand(60, $seconds))->format('Y-m-d H:i:s'),
            ];

            if (count($rows) === 2000) {
                DB::table('audit_logs')->insert($rows);
                $rows = [];
            }
        }

        if ($rows !== []) {
            DB::table('audit_logs')->insert($rows);
        }
    }

    private function backups(): void
    {
        $today = CarbonImmutable::today();
        $rows = [];

        for ($day = self::BACKUP_RUNS - 1; $day >= 0; $day--) {
            $start = $today->subDays($day)->setTime(2, 0);
            $failed = mt_rand(0, 32) === 0 && $day > 0;
            $stamp = $start->format('Y-m-d_His');

            $rows[] = [
                'started_at' => $start->format('Y-m-d H:i:s'),
                'finished_at' => $start->addSeconds(mt_rand(20, 90))->format('Y-m-d H:i:s'),
                'status' => $failed ? 'failed' : 'success',
                'file_name' => $failed ? null : "patient_census_{$stamp}.sql.gz",
                'size_bytes' => $failed ? null : mt_rand(8, 30) * 1048576,
                'zip_name' => $failed ? null : "patient_census_{$stamp}_pictures.zip",
                'zip_size_bytes' => $failed ? null : 350000,
                'message' => $failed ? 'mysqldump: Got error: 2013: Lost connection to server during query' : null,
            ];
        }

        DB::table('backup_runs')->insert($rows);
    }
}
