<?php

/**
 * Developer tool (Step 7g, docs/developer-guide.md "Performance"). Never used on the server.
 *
 * Opens every screen of a server started with perf-router.php, as the perf.*
 * accounts of PerformanceSeeder, and prints per screen: server time (first,
 * median and best of N), queries, response size and the time the client waited.
 * Reads and writes ONLY patient_census_test.
 *
 *   PERF_LOG=<file> PERF_PASSWORD=... php scripts/perf/perf-drive.php <base url> <out.json> [reps] [only-prefix]
 */
$baseUrl = rtrim($argv[1], '/');
$outFile = $argv[2];
$reps = (int) ($argv[3] ?? 5);
$only = $argv[4] ?? null;
$log = (string) getenv('PERF_LOG');
$password = (string) getenv('PERF_PASSWORD');

/** The database login from the system's .env; the database is always the test one. */
function perfPdo(): PDO
{
    $env = [];
    foreach (file(dirname(__DIR__, 2).'/.env', FILE_IGNORE_NEW_LINES) ?: [] as $line) {
        if (preg_match('/^\s*([A-Z_]+)\s*=\s*(.*)$/', $line, $m)) {
            $env[$m[1]] = trim(trim($m[2]), '"\'');
        }
    }

    return new PDO("mysql:host={$env['DB_HOST']};port={$env['DB_PORT']};dbname=patient_census_test", $env['DB_USERNAME'], $env['DB_PASSWORD'] ?? '');
}

$pdo = perfPdo();

final class PerfClient
{
    public string $version = '';

    private string $jar;

    private static int $seq = 0;

    public function __construct(private string $base, private string $log)
    {
        $this->jar = (string) tempnam(sys_get_temp_dir(), 'jar');
    }

    public function xsrf(): string
    {
        foreach (file($this->jar) ?: [] as $line) {
            $parts = explode("\t", trim($line));
            if (count($parts) === 7 && $parts[5] === 'XSRF-TOKEN') {
                return urldecode($parts[6]);
            }
        }

        return '';
    }

    /** @return array{status: int, bytes: int, client_ms: float, server: array<string, mixed>|null, body: string, location: string|null} */
    public function request(string $method, string $path, string $mode = 'inertia', ?array $data = null, ?array $file = null): array
    {
        $id = (++self::$seq).'-'.getmypid();
        $headers = ['X-Perf-Id: '.$id];

        $headers = [...$headers, ...match ($mode) {
            'inertia' => ['X-Inertia: true', 'X-Inertia-Version: '.$this->version, 'X-Requested-With: XMLHttpRequest', 'Accept: text/html, application/xhtml+xml'],
            'json' => ['Accept: application/json', 'X-Requested-With: XMLHttpRequest'],
            'file' => ['Accept: */*'],
            default => ['Accept: text/html'],
        }];

        if ($method !== 'GET') {
            $headers[] = 'X-XSRF-TOKEN: '.$this->xsrf();
        }

        $ch = curl_init($this->base.$path);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => $method, CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true,
            CURLOPT_COOKIEJAR => $this->jar, CURLOPT_COOKIEFILE => $this->jar, CURLOPT_FOLLOWLOCATION => false, CURLOPT_TIMEOUT => 300,
        ]);

        if ($file !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, ['file' => new CURLFile($file['path'], $file['mime'], $file['name'])]);
        } elseif ($data !== null && $mode === 'inertia') {
            $headers[] = 'Content-Type: application/json';
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        } elseif ($data !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
        }

        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        $raw = (string) curl_exec($ch);
        $info = curl_getinfo($ch);
        $head = substr($raw, 0, $info['header_size']);
        $body = substr($raw, $info['header_size']);
        preg_match('/^Location:\s*(\S+)/mi', $head, $loc);

        // The router writes its line after the response is sent: wait for it.
        $server = null;
        for ($i = 0; $i < 400 && $server === null; $i++) {
            foreach (array_reverse(file($this->log) ?: []) as $line) {
                $row = json_decode($line, true);
                if (($row['id'] ?? null) === $id) {
                    $server = $row;
                    break;
                }
            }
            $server === null && usleep(10000);
        }

        return ['status' => (int) $info['http_code'], 'bytes' => strlen($body), 'client_ms' => round($info['total_time'] * 1000, 1), 'server' => $server, 'body' => $body, 'location' => $loc[1] ?? null];
    }

    public function login(string $username, string $password): void
    {
        $page = $this->request('GET', '/login', 'html');
        if (preg_match('/&quot;version&quot;:&quot;([^&]*)&quot;/', $page['body'], $m) || preg_match('/"version":"([^"]*)"/', $page['body'], $m)) {
            $this->version = html_entity_decode($m[1]);
        }
        $done = $this->request('POST', '/login', 'html', ['username' => $username, 'password' => $password]);
        if (! in_array($done['status'], [302, 303], true) || str_contains((string) $done['location'], '/login')) {
            fwrite(STDERR, "Login failed for {$username}: {$done['status']} {$done['location']}\n");
            exit(1);
        }
    }
}

$results = [];

$measure = function (string $name, callable $call, int $times) use (&$results, $only): void {
    if ($only !== null && ! str_starts_with($name, $only)) {
        return;
    }
    $runs = [];
    for ($i = 0; $i < $times; $i++) {
        $runs[] = $call($i);
    }
    $server = array_map(fn ($r) => $r['server']['ms'] ?? null, $runs);
    $sorted = array_values(array_filter($server, fn ($v) => $v !== null));
    sort($sorted);
    $last = end($runs);
    $results[$name] = [
        'status' => $last['status'],
        'first_ms' => $server[0],
        'median_ms' => $sorted === [] ? null : $sorted[intdiv(count($sorted) - 1, 2)],
        'min_ms' => $sorted[0] ?? null,
        'queries' => $last['server']['queries'] ?? null,
        'query_ms' => $last['server']['query_ms'] ?? null,
        'kb' => round($last['bytes'] / 1024, 1),
        'client_ms' => $last['client_ms'],
        'slow' => array_values(array_unique(array_merge([], ...array_map(fn ($r) => array_map(fn ($s) => $s['ms'].'ms '.$s['sql'], $r['server']['slow'] ?? []), $runs)))),
    ];
    $r = $results[$name];
    fprintf(STDERR, "%-40s %3d first %7.1f  median %7.1f  best %7.1f  q %4s  %7.1f KB\n", $name, $r['status'], $r['first_ms'], $r['median_ms'], $r['min_ms'], $r['queries'], $r['kb']);
};

$clearReports = fn () => $pdo->exec("DELETE FROM cache WHERE `key` LIKE '%report:%'");

// --- Login ------------------------------------------------------------------------------
$measure('login page (full HTML)', fn () => (new PerfClient($baseUrl, $log))->request('GET', '/login', 'html'), $reps);
$measure('login submit', function () use ($baseUrl, $log, $password) {
    $c = new PerfClient($baseUrl, $log);
    $c->request('GET', '/login', 'html');

    return $c->request('POST', '/login', 'html', ['username' => 'perf.encoder3', 'password' => $password]);
}, 3);

// --- Encoder ----------------------------------------------------------------------------
$encoder = new PerfClient($baseUrl, $log);
$encoder->login('perf.encoder1', $password);

$measure('encode open (full HTML)', fn () => $encoder->request('GET', '/encode', 'html'), $reps);
$measure('encode open', fn () => $encoder->request('GET', '/encode'), $reps);

foreach (['sa', 'san', 'sant', 'santos', 'santos ma', 'dela cruz j', 'P-000123', '123'] as $q) {
    $measure("patient search '{$q}'", fn () => $encoder->request('GET', '/patients/search?q='.rawurlencode($q), 'json'), 3);
}

$rank = $pdo->query('select id, branch_id from ranks where deleted_at is null and is_other = 0 order by id limit 1')->fetch(PDO::FETCH_ASSOC);
$diagnosis = (int) $pdo->query('select id from diagnoses where deleted_at is null and is_other = 0 order by id limit 1')->fetchColumn();
$measure('encode save (POST)', function (int $i) use ($encoder, $pdo, $rank, $diagnosis) {
    $p = $pdo->query('select * from patients where deleted_at is null and id = '.(100 + $i))->fetch(PDO::FETCH_ASSOC);

    return $encoder->request('POST', '/visits', 'inertia', [
        'patient_id' => (int) $p['id'], 'last_name' => $p['last_name'], 'first_name' => $p['first_name'],
        'middle_initial' => $p['middle_initial'] === null ? null : rtrim($p['middle_initial'], '.'),
        'sex' => $p['sex'], 'birthdate' => $p['birthdate'], 'age' => 40,
        'branch_id' => (int) $rank['branch_id'], 'rank_id' => (int) $rank['id'], 'rank_other' => null,
        'diagnosis_id' => $diagnosis, 'diagnosis_other' => null, 'category' => 'ER', 'remarks' => 'perf',
    ]);
}, $reps);
$measure('encode after save (GET)', fn () => $encoder->request('GET', '/encode'), $reps);

// --- Admin ------------------------------------------------------------------------------
$admin = new PerfClient($baseUrl, $log);
$admin->login('perf.admin', $password);

$visitPages = (int) ceil($pdo->query('select count(*) from visits where deleted_at is null')->fetchColumn() / 10);
$patientPages = (int) ceil($pdo->query('select count(*) from patients where deleted_at is null')->fetchColumn() / 10);
$auditPages = (int) ceil($pdo->query('select count(*) from audit_logs')->fetchColumn() / 10);
$adminId = (int) $pdo->query("select id from users where username = 'perf.admin'")->fetchColumn();

$pages = [
    'records visits p1' => '/records/visits',
    'records visits filters' => "/records/visits?category=ER&branch={$rank['branch_id']}&q=santos",
    'records visits search name' => '/records/visits?from=&to=&q=dela%20cruz',
    'records visits late page' => "/records/visits?from=&to=&page={$visitPages}",
    'records visits late page 50' => '/records/visits?from=&to=&per_page=50&page='.(int) ceil($visitPages / 5),
    'records patients p1' => '/records/patients',
    'records patients search' => '/records/patients?q=santos',
    'records patients late page' => "/records/patients?page={$patientPages}",
    'lists' => '/settings/lists',
    'trash p1' => '/trash',
    'trash late page' => '/trash?page=11',
    'audit p1' => '/audit-log',
    'audit filter action' => '/audit-log?from=&to=&action=user.login',
    'audit filter user' => "/audit-log?from=&to=&user={$adminId}",
    'audit filter record' => '/audit-log?from=&to=&record=V-000123',
    'audit late page' => "/audit-log?from=&to=&page={$auditPages}",
    'audit late page 50' => '/audit-log?from=&to=&per_page=50&page='.(int) ceil($auditPages / 5),
    'backups p1' => '/backups',
    'backups late page' => '/backups?page=30',
    'system health' => '/system/health',
    'users' => '/users',
    'hospital settings' => '/settings/hospital',
    'import page' => '/records/import',
];

foreach ($pages as $name => $path) {
    $measure($name, fn () => $admin->request('GET', $path), $reps);
}

$measure('records visits p1 (full HTML)', fn () => $admin->request('GET', '/records/visits', 'html'), $reps);

$month = date('Y-m', strtotime('first day of last month'));
$year = date('Y', strtotime('first day of last month'));
$quarter = (int) ceil(((int) date('n', strtotime('first day of last month'))) / 3);
$periods = [
    'monthly' => "type=monthly&month={$month}",
    'quarterly' => "type=quarterly&year={$year}&quarter={$quarter}",
    'annual' => "type=annual&year={$year}",
];

foreach ($periods as $kind => $query) {
    $measure("reports generate {$kind} (counted)", function () use ($admin, $clearReports, $query) {
        $clearReports();

        return $admin->request('GET', "/reports?{$query}");
    }, 3);
    $measure("reports generate {$kind} (remembered)", fn () => $admin->request('GET', "/reports?{$query}"), 3);
}

// Exports right after Generate (remembered), as the user does it. 20 a minute at most.
foreach ($periods as $kind => $query) {
    $measure("excel {$kind}", fn () => $admin->request('GET', "/reports/export/excel?{$query}", 'file'), 2);
    $measure("pdf {$kind}", fn () => $admin->request('GET', "/reports/export/pdf?{$query}&scope=all", 'file'), 2);
}
$measure('excel monthly (counted)', function () use ($admin, $clearReports, $periods) {
    $clearReports();

    return $admin->request('GET', "/reports/export/excel?{$periods['monthly']}", 'file');
}, 2);

// Import: a 200-row downtime file made from the test lists, then its preview.
$csv = dirname($outFile).'/import-200.csv';
if (! is_file($csv)) {
    $pairs = $pdo->query('select b.name branch, r.name rank_name from ranks r join branches b on b.id = r.branch_id where r.deleted_at is null and r.is_other = 0 order by r.id')->fetchAll(PDO::FETCH_ASSOC);
    $dx = $pdo->query('select name from diagnoses where deleted_at is null and is_other = 0 order by id')->fetchAll(PDO::FETCH_COLUMN);
    $pts = $pdo->query('select patient_no, last_name, first_name, sex, birthdate from patients where deleted_at is null order by id limit 150')->fetchAll(PDO::FETCH_ASSOC);
    $f = fopen($csv, 'w');
    fputcsv($f, ['Visit Date *', 'Visit Time *', 'Patient ID', 'Last Name *', 'First Name *', 'M.I.', 'Sex *', 'Birthdate *', 'Age', 'Branch *', 'Rank / Type *', 'Rank Other (specify)', 'Diagnosis *', 'Diagnosis Other (specify)', 'Category *', 'Remarks', 'Written by *'], escape: '');
    mt_srand(5);
    for ($i = 0; $i < 200; $i++) {
        $p = $pts[$i % 150];
        $pr = $pairs[mt_rand(0, count($pairs) - 1)];
        $new = $i >= 150;
        fputcsv($f, [sprintf('%s-%02d', $month, mt_rand(1, 28)), sprintf('%02d:%02d', mt_rand(0, 23), mt_rand(0, 59)), $new ? '' : $p['patient_no'], $new ? "Newperson{$i}" : $p['last_name'], $new ? 'Ana' : $p['first_name'], '', $p['sex'], $p['birthdate'], '', $pr['branch'], $pr['rank_name'], '', $dx[mt_rand(0, count($dx) - 1)], '', 'ER', 'downtime', 'Nurse Reyes'], escape: '');
    }
    fclose($f);
}

$measure('import upload (POST)', fn () => $admin->request('POST', '/records/import', 'inertia', null, ['path' => $csv, 'mime' => 'text/csv', 'name' => 'downtime.csv']), 1);
$upload = $admin->request('POST', '/records/import', 'inertia', null, ['path' => $csv, 'mime' => 'text/csv', 'name' => 'downtime.csv']);
if ($upload['location'] !== null) {
    $path = parse_url($upload['location'], PHP_URL_PATH).'?'.parse_url($upload['location'], PHP_URL_QUERY);
    $measure('import preview', fn () => $admin->request('GET', $path), $reps);
} else {
    fwrite(STDERR, "Import upload gave {$upload['status']}\n");
}

file_put_contents($outFile, json_encode($results, JSON_PRETTY_PRINT));
