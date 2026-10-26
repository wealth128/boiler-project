<?php

use App\Http\Controllers\Audit\AuditRows;
use App\Models\Concerns\MovesToTrash;
use App\Services\AuditActions;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/*
 * Step 7g: the Audit log's Record filter lists the "deleted permanently" actions
 * (AuditRows::purgedActions) instead of LIKE '%.purged', so an index can be used.
 * A new kind of record that can be deleted permanently must therefore be in that
 * list, or its entries could no longer be found by their record number or name.
 * This test finds every ".purged" action the code can write, from three places,
 * and fails when one is missing from the list.
 */

/** @return list<string> */
function purgedFromLiterals(): array
{
    $found = [];
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(base_path('app'), FilesystemIterator::SKIP_DOTS));

    foreach ($iterator as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }

        // Quoted action names such as 'visit.purged' (comments are not quoted names).
        if (preg_match_all('/[\'"]([a-z_]+\.purged)[\'"]/', (string) file_get_contents($file->getPathname()), $matches)) {
            array_push($found, ...$matches[1]);
        }
    }

    return array_values(array_unique($found));
}

/**
 * The actions the observer writes for every record type that goes to Trash:
 * the type's name in the singular, then ".purged" (AuditObserver::forceDeleted).
 *
 * @return list<string>
 */
function purgedFromTrashModels(): array
{
    $actions = [];

    foreach (glob(app_path('Models/*.php')) ?: [] as $path) {
        $class = 'App\\Models\\'.basename($path, '.php');

        if (is_subclass_of($class, Model::class) && in_array(MovesToTrash::class, class_uses_recursive($class), true)) {
            $actions[] = Str::singular((new $class)->getMorphClass()).'.purged';
        }
    }

    return $actions;
}

it('lists every ".purged" action of the code in the Audit Record filter', function () {
    $listed = AuditRows::purgedActions();

    $labelled = array_values(array_filter(AuditActions::codes(), fn (string $code): bool => str_ends_with($code, '.purged')));

    // The three sources are not empty, so a broken search cannot pass by finding nothing.
    expect($labelled)->not->toBeEmpty()
        ->and(purgedFromLiterals())->not->toBeEmpty()
        ->and(purgedFromTrashModels())->not->toBeEmpty();

    $missing = array_values(array_diff(
        array_unique([...$labelled, ...purgedFromLiterals(), ...purgedFromTrashModels()]),
        $listed,
    ));

    expect($missing)->toBe([], 'Add the record type to the morph map, or to AuditRows::purgedActions(): '.implode(', ', $missing));
});

it('keeps "month.report_purged" out of the ".purged" rule on purpose', function () {
    // It ends in "_purged", so the old LIKE '%.purged' never matched it either: nothing changed.
    expect(AuditRows::purgedActions())->not->toContain('month.report_purged')
        ->and(AuditActions::codes())->toContain('month.report_purged');
});
