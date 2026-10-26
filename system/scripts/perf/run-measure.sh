#!/bin/sh
# Developer tool (Step 7g, docs/developer-guide.md "Performance"). Never used on the server.
#
#   PERF_OUT=<folder> PERF_PASSWORD=... sh scripts/perf/run-measure.sh <system dir> <port> <label> [reps] [throttled]
#
# Works ONLY on patient_census_test. The first run seeds it with PerformanceSeeder and keeps a
# dump in PERF_OUT; every run reloads that dump, so before and after see the same data.
# Starts php -S for <system dir> (your folder for dev mode, a copy with an optimized
# production .env for production mode), opts it out of power throttling unless "throttled",
# opens every screen (perf-drive.php) and writes <label>.json, .log and .txt in PERF_OUT.
TOOLS="$(cd "$(dirname "$0")" && pwd)"
APP="$(cd "$TOOLS/../.." && pwd)"
BASE="$1"; PORT="$2"; LABEL="$3"; REPS="${4:-5}"; MODE="$5"
OUT="${PERF_OUT:?set PERF_OUT}"
mkdir -p "$OUT"

DUMP_EXE="$(grep '^MYSQLDUMP_PATH=' "$APP/.env" | cut -d= -f2- | tr -d '"')"
MYSQL_EXE="$(dirname "$DUMP_EXE")/mysql.exe"
php -r '
$env = [];
foreach (file($argv[1], FILE_IGNORE_NEW_LINES) as $l) { if (preg_match("/^\s*([A-Z_]+)\s*=\s*(.*)$/", $l, $m)) $env[$m[1]] = trim(trim($m[2]), "\"\x27"); }
file_put_contents($argv[2], "[client]\nhost={$env["DB_HOST"]}\nport={$env["DB_PORT"]}\nuser={$env["DB_USERNAME"]}\npassword=".($env["DB_PASSWORD"] ?? "")."\n");' "$APP/.env" "$OUT/my.cnf"

if [ ! -f "$OUT/perf-seed.sql" ]; then
  (cd "$APP" && export DB_DATABASE=patient_census_test && \
   [ "$(php artisan tinker --execute='echo DB::connection()->getDatabaseName();' 2>/dev/null | tail -1)" = patient_census_test ] && \
   php artisan migrate:fresh --force >/dev/null && \
   php -d memory_limit=1G artisan db:seed --class=PerformanceSeeder --force >/dev/null) || { echo "seeding failed"; exit 1; }
  "$DUMP_EXE" --defaults-extra-file="$OUT/my.cnf" --single-transaction --no-tablespaces patient_census_test > "$OUT/perf-seed.sql" || exit 1
fi

"$MYSQL_EXE" --defaults-extra-file="$OUT/my.cnf" patient_census_test < "$OUT/perf-seed.sql" || exit 1
# The dump is from before any new migration: bring it up to date (test database only).
(cd "$BASE" && DB_DATABASE=patient_census_test php artisan migrate --force >/dev/null) || exit 1
"$MYSQL_EXE" --defaults-extra-file="$OUT/my.cnf" patient_census_test -e "ANALYZE TABLE visits, patients, audit_logs, backup_runs" >/dev/null

: > "$OUT/$LABEL.log"
(cd "$BASE" && DB_DATABASE=patient_census_test PERF_BASE="$BASE" PERF_LOG="$OUT/$LABEL.log" nohup php -S 127.0.0.1:$PORT -t public "$TOOLS/perf-router.php" > "$OUT/$LABEL.server.out" 2>&1 &)
sleep 1
[ "$MODE" != "throttled" ] && powershell -NoProfile -ExecutionPolicy Bypass -File "$(cygpath -w "$TOOLS/unthrottle.ps1")" -Port $PORT
curl -s -o /dev/null http://127.0.0.1:$PORT/up
PERF_LOG="$OUT/$LABEL.log" php -d memory_limit=512M "$TOOLS/perf-drive.php" http://127.0.0.1:$PORT "$OUT/$LABEL.json" $REPS 2>&1 | tee "$OUT/$LABEL.txt"
powershell -NoProfile -Command "\$c = Get-NetTCPConnection -State Listen -LocalPort $PORT -ErrorAction SilentlyContinue | Select-Object -First 1; if (\$c) { Stop-Process -Id \$c.OwningProcess -Force }"
