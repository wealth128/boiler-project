<?php

namespace App\Http\Controllers\Records;

use App\Enums\Permission;
use App\Exports\Import\DowntimeTemplate;
use App\Exports\Import\TemplateLists;
use App\Http\Controllers\Controller;
use App\Http\Requests\Records\ConfirmImportRequest;
use App\Http\Requests\Records\UploadImportRequest;
use App\Models\ImportBatch;
use App\Models\MonthClosure;
use App\Models\User;
use App\Models\Visit;
use App\Services\Import\ImportAnalyzer;
use App\Services\Import\ImportColumns;
use App\Services\Import\ImportFileException;
use App\Services\Import\ImportFileStore;
use App\Services\Import\ImportService;
use App\Services\TablePaging;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Maatwebsite\Excel\Excel as ExcelWriter;
use Maatwebsite\Excel\Facades\Excel;

/**
 * Records > Import: the downtime import (Step 6j). Admin only: role:admin on
 * the routes and the "import-visits" Gate here.
 *
 * 1. Download template: the offices fill it in while the system is down.
 * 2. Upload: the file is kept (storage/app/private/imports, at most an hour)
 *    and the page shows a PREVIEW of every row. Nothing is saved yet.
 * 3. Confirm: only the ticked rows are imported, all or nothing.
 * 4. Past imports, and Undo for a whole import.
 *
 * The checks are in App\Services\Import (ImportAnalyzer), the saving and the
 * undo in ImportService.
 */
class ImportController extends Controller
{
    public function __construct(private readonly ImportService $imports, private readonly ImportFileStore $store) {}

    public function index(Request $request): Response|RedirectResponse
    {
        Gate::authorize(Permission::ImportVisits->value);

        /** @var User $user */
        $user = $request->user();

        $this->store->purgeExpired();

        $preview = null;
        $token = $request->query('file');

        if ($token !== null) {
            $preview = $this->preview($user, is_string($token) ? $token : '');

            if ($preview instanceof RedirectResponse) {
                return $preview;
            }
        }

        $page = ImportBatch::query()
            ->with(['importer', 'undoer'])
            ->orderByDesc('id')
            ->paginate(TablePaging::perPage($request))
            ->withQueryString();

        if ($redirect = TablePaging::redirectPastLastPage($request, $page)) {
            return $redirect;
        }

        return Inertia::render('records/import', [
            'preview' => $preview,
            'limits' => [
                'max_rows' => ImportColumns::MAX_ROWS,
                'max_mb' => ImportColumns::MAX_KILOBYTES / 1024,
                'earliest' => Carbon::parse(ImportColumns::EARLIEST_DATE)->format('F Y'),
            ],
            'batches' => [
                'data' => $this->batchRows($page->items()),
                'meta' => TablePaging::meta($page),
            ],
        ]);
    }

    /**
     * The template: an .xlsx built from the lists as they are today. It is
     * generated for each download and sent straight away; nothing is stored.
     */
    public function template(): HttpResponse
    {
        Gate::authorize(Permission::ImportVisits->value);

        $workbook = new DowntimeTemplate(TemplateLists::current(), now());

        return response(Excel::raw($workbook, ExcelWriter::XLSX), 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="census-downtime-template_'.now()->format('Y-m-d').'.xlsx"',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    /**
     * Keep the file and show its preview. A file that can't be used at all
     * (not a spreadsheet, wrong header row, over 2,000 rows) is deleted and
     * the reason is shown under the file field.
     */
    public function upload(UploadImportRequest $request): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        $this->store->purgeExpired();

        $staged = $this->store->store($request->file('file'), $request->extension(), $user);

        try {
            $this->imports->preview($staged); // checks the file and keeps the result for the page
        } catch (ImportFileException $e) {
            $this->store->delete($staged->token);

            throw ValidationException::withMessages(['file' => $e->getMessage()]);
        }

        return to_route('records.import', ['file' => $staged->token]);
    }

    /**
     * Import the ticked rows.
     */
    public function confirm(ConfirmImportRequest $request): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        $token = (string) $request->validated('token');
        $staged = $this->store->find($token, $user);

        if ($staged === null) {
            return $this->toast('warning', $this->store->wasImported($token) ? 'This file was already imported.' : 'That file is no longer available. Upload it again.');
        }

        try {
            $outcome = $this->imports->confirm($user, $staged, $request->rowNumbers(), $request->newPatientRowNumbers());
        } catch (ImportFileException $e) {
            $this->store->delete($staged->token);

            return $this->toast('error', $e->getMessage());
        }

        $held = $outcome->heldBack === [] ? '' : ' Not imported: '.$this->rowList($outcome->heldBack).(count($outcome->heldBack) === 1 ? ' now looks' : ' now look').' like a possible duplicate of a visit that was saved after the preview. Check the preview again.';

        if ($outcome->batch === null) {
            return $this->toast('warning', 'Nothing was imported.'.$held);
        }

        $count = $outcome->batch->imported_count;
        $skipped = $outcome->batch->skipped_count;

        return $this->toast($held === '' ? 'success' : 'warning', "Imported {$count} ".($count === 1 ? 'visit' : 'visits').($skipped > 0 ? ", skipped {$skipped}." : '.').$held);
    }

    /**
     * Throw the waiting file away.
     */
    public function cancel(Request $request): RedirectResponse
    {
        Gate::authorize(Permission::ImportVisits->value);

        /** @var User $user */
        $user = $request->user();

        $staged = $this->store->find((string) $request->input('token'), $user);

        if ($staged !== null) {
            $this->store->delete($staged->token);
        }

        return to_route('records.import');
    }

    /**
     * Move a whole import to Trash.
     */
    public function undo(Request $request, ImportBatch $batch): RedirectResponse
    {
        Gate::authorize(Permission::ImportVisits->value);

        /** @var User $user */
        $user = $request->user();

        $moved = $this->imports->undo($user, $batch);

        return $this->toast('success', "Import undone: {$moved['visits']} ".($moved['visits'] === 1 ? 'visit' : 'visits')." moved to Trash. They can be restored from Trash one by one.");
    }

    // --- Pieces ----------------------------------------------------------------

    /**
     * The preview of a waiting file, or a redirect with a message when it is
     * gone (an hour passed, or it was imported or cancelled in another tab).
     *
     * @return array<string, mixed>|RedirectResponse
     */
    private function preview(User $user, string $token): array|RedirectResponse
    {
        $staged = $this->store->find($token, $user);

        if ($staged === null) {
            return $this->toast('warning', 'That file is no longer available. Upload it again.');
        }

        try {
            $preview = $this->imports->preview($staged);
        } catch (ImportFileException $e) {
            $this->store->delete($staged->token);

            return $this->toast('error', $e->getMessage());
        }

        return [
            'token' => $staged->token,
            'file_name' => $staged->name,
            'summary' => $preview['summary'],
            'rows' => $preview['rows'],
        ];
    }

    /**
     * "row 5", "rows 5 and 7", "rows 5, 7 and 9" (a long list is cut).
     *
     * @param  list<int>  $numbers
     */
    private function rowList(array $numbers): string
    {
        $shown = array_slice($numbers, 0, 10);
        $more = count($numbers) - count($shown);
        $last = array_pop($shown);
        $text = $shown === [] ? (string) $last : implode(', ', $shown).' and '.$last;

        return (count($numbers) === 1 ? 'row ' : 'rows ').$text.($more > 0 ? " (and {$more} more)" : '');
    }

    /**
     * The "Past imports" rows. Undo is offered unless the import was already
     * undone or one of its visits is in a closed month.
     *
     * @param  array<int, ImportBatch>  $batches
     * @return list<array<string, mixed>>
     */
    private function batchRows(array $batches): array
    {
        $ids = array_map(fn (ImportBatch $batch): int => $batch->id, $batches);

        $closed = MonthClosure::query()->where('is_closed', true)->pluck('period')->flip();

        // The months each (not yet undone) import's visits are in.
        $months = Visit::query()
            ->whereIn('import_batch_id', $ids)
            ->selectRaw("import_batch_id, DATE_FORMAT(visited_at, '%Y-%m') AS month")
            ->distinct()
            ->get()
            ->groupBy('import_batch_id');

        return array_map(function (ImportBatch $batch) use ($months, $closed): array {
            $blocked = $batch->isUndone() ? null : $months->get($batch->id, collect())
                ->pluck('month')
                ->sort()
                ->first(fn (string $period): bool => $closed->has($period));

            return [
                'id' => $batch->id,
                'file_name' => $batch->file_name,
                'imported_at' => $batch->created_at->toIso8601String(),
                'imported_by' => $batch->importer->username,
                'original_rows' => $batch->original_rows,
                'imported_count' => $batch->imported_count,
                'skipped_count' => $batch->skipped_count,
                'undone_at' => $batch->undone_at?->toIso8601String(),
                'undone_by' => $batch->undoer?->username,
                'can_undo' => ! $batch->isUndone() && $blocked === null,
                'blocked_month' => $blocked === null ? null : Carbon::parse($blocked.'-01')->format('F Y'),
            ];
        }, array_values($batches));
    }

    private function toast(string $type, string $message): RedirectResponse
    {
        Inertia::flash('toast', ['type' => $type, 'message' => $message]);

        return to_route('records.import');
    }
}
