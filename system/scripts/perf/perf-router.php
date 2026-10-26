<?php

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Http\Request;

/**
 * Developer tool (Step 7g, docs/developer-guide.md "Performance"). Never used on the server.
 *
 * Router for "php -S": serves the app like "php artisan serve" and writes one
 * JSON line per request to PERF_LOG: server time, queries, query time, the
 * queries slower than 50 ms, response bytes and peak memory.
 *
 *   PERF_BASE=<system dir> PERF_LOG=<file> php -S 127.0.0.1:8123 -t public scripts/perf/perf-router.php
 */
$base = (string) getenv('PERF_BASE');
$uri = urldecode((string) parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH));

if ($uri !== '/' && file_exists($base.'/public'.$uri)) {
    return false;
}

define('LARAVEL_START', microtime(true));

require $base.'/vendor/autoload.php';

$app = require_once $base.'/bootstrap/app.php';

$perf = ['queries' => 0, 'query_ms' => 0.0, 'slow' => []];

$app->booted(function ($app) use (&$perf): void {
    $app['events']->listen(QueryExecuted::class, function ($query) use (&$perf): void {
        $perf['queries']++;
        $perf['query_ms'] += $query->time;

        if ($query->time > 50) {
            $perf['slow'][] = ['ms' => round($query->time, 1), 'sql' => mb_substr($query->sql, 0, 400)];
        }
    });
});

$bytes = 0;
ob_start(function (string $chunk) use (&$bytes): string {
    $bytes += strlen($chunk);

    return $chunk;
}, 1);

$app->handleRequest(Request::capture());

file_put_contents((string) getenv('PERF_LOG'), json_encode([
    'id' => $_SERVER['HTTP_X_PERF_ID'] ?? null,
    'method' => $_SERVER['REQUEST_METHOD'],
    'uri' => $_SERVER['REQUEST_URI'],
    'status' => http_response_code(),
    'ms' => round((microtime(true) - LARAVEL_START) * 1000, 1),
    'queries' => $perf['queries'],
    'query_ms' => round($perf['query_ms'], 1),
    'slow' => $perf['slow'],
    'bytes' => $bytes,
    'peak_mb' => round(memory_get_peak_usage(true) / 1048576, 1),
]).PHP_EOL, FILE_APPEND | LOCK_EX);
