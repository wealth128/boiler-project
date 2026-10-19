<?php

namespace App\Http\Controllers\Backup;

use App\Enums\BackupMarkType;
use App\Enums\BackupStatus;
use App\Enums\Permission;
use App\Http\Controllers\Controller;
use App\Models\BackupMark;
use App\Models\BackupRun;
use App\Services\AuditLogger;
use App\Services\BackupMonitor;
use App\Services\BackupService;
use App\Services\TablePaging;
use App\Services\TestRestoreService;
use App\Services\UsbCopyFailed;
use App\Services\UsbCopyService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Backups (planning/architecture.md section 4). Admin only: role:admin on
 * the routes and the "view-backups" Gate here.
 *
 * The screen shows the status, the history and two reminders. Running a
 * backup and the backup logic live in BackupService, the warnings in
 * BackupMonitor, the USB copy in UsbCopyService and the test restore in
 * TestRestoreService. There is no download and no restore of the live data
 * here: backup files hold all patient data. The server copies them to a USB
 * drive plugged into the server itself.
 *
 * Nothing on this page shows the database login: error text goes through
 * BackupMonitor::scrub() first.
 */
class BackupController extends Controller
{
    public function __construct(
        private readonly BackupMonitor $monitor,
        private readonly AuditLogger $audit,
        private readonly UsbCopyService $usb,
    ) {}

    public function index(Request $request): Response|RedirectResponse
    {
        Gate::authorize(Permission::ViewBackups->value);

        $page = BackupRun::query()
            ->orderByDesc('started_at')->orderByDesc('id')
            ->paginate(TablePaging::perPage($request))
            ->withQueryString();

        if ($redirect = TablePaging::redirectPastLastPage($request, $page)) {
            return $redirect;
        }

        $success = $this->monitor->lastSuccess();

        return Inertia::render('backups/index', [
            'status' => [
                'last_success' => $success === null ? null : [
                    'finished_at' => $success->finished_at->toIso8601String(),
                    'file_name' => $success->file_name,
                    'size_mb' => $this->megabytes($success->size_bytes),
                    'zip_name' => $success->zip_name,
                    'zip_size_mb' => $this->megabytes($success->zip_size_bytes),
                ],
                'next_run' => $this->monitor->nextRun()->toIso8601String(),
                'keep_days' => (int) config('census.backup.keep_days'),
                'folder' => (string) config('census.backup.path'),
                'problem' => $this->monitor->problem()['message'] ?? null,
                'test_restore_problem' => $this->monitor->testRestoreProblem()['message'] ?? null,
            ],
            'testRestore' => $this->testRestoreInfo(),
            // Asked for when the Copy to USB window opens: listing the drives takes a moment.
            'drives' => Inertia::optional(fn (): array => collect($this->usb->drives())->map(fn (array $drive): array => [
                'letter' => $drive['letter'],
                'label' => $drive['label'],
                'free_mb' => (int) round($drive['free_bytes'] / 1048576),
                'size_mb' => (int) round($drive['size_bytes'] / 1048576),
            ])->all()),
            'marks' => collect(BackupMarkType::cases())->map(fn (BackupMarkType $type): array => $this->markRow($type))->all(),
            'runs' => [
                'data' => $page->getCollection()->map(fn (BackupRun $run): array => [
                    'id' => $run->id,
                    'started_at' => $run->started_at->toIso8601String(),
                    'finished_at' => $run->finished_at->toIso8601String(),
                    'status' => $run->status->value,
                    'file_name' => $run->file_name,
                    'size_mb' => $this->megabytes($run->size_bytes),
                    'zip_name' => $run->zip_name,
                    'zip_size_mb' => $this->megabytes($run->zip_size_bytes),
                    // Only failures show the message (a success only says how many old files were removed).
                    'error' => $run->status === BackupStatus::Failed ? $this->monitor->scrub((string) $run->message) : null,
                ])->values()->all(),
                'meta' => TablePaging::meta($page),
            ],
        ]);
    }

    /**
     * Run a backup now. One at a time: while another runs, the answer is
     * "A backup is already running."
     */
    public function run(BackupService $backups): RedirectResponse
    {
        Gate::authorize(Permission::ViewBackups->value);

        // mysqldump of a big database can outlast PHP's 30 second default.
        set_time_limit(0);

        $run = $backups->runIfIdle();

        if ($run === null) {
            return $this->toast('error', 'A backup is already running.');
        }

        if ($run->status === BackupStatus::Failed) {
            $error = $this->monitor->shortError($run->message);

            $this->audit->log('backup.run_manual', $run, ['label' => 'Manual backup', 'result' => 'failed', 'error' => $error]);

            return $this->toast('error', "Backup failed: {$error}");
        }

        $size = BackupMonitor::megabytes($run->size_bytes);
        $zipSize = BackupMonitor::megabytes($run->zip_size_bytes);

        $this->audit->log('backup.run_manual', $run, ['label' => $run->file_name, 'result' => 'success', 'size' => $size, 'zip' => $run->zip_name, 'zip_size' => $zipSize]);

        return $this->toast('success', "Backup finished: {$run->file_name}, {$size}, and the pictures zip, {$zipSize}");
    }

    /**
     * Copy the latest backup to a USB drive plugged into the SERVER. Nothing
     * is downloaded. One at a time: it shares the backup lock.
     */
    public function usbCopy(Request $request): RedirectResponse
    {
        Gate::authorize(Permission::ViewBackups->value);

        $drive = (string) $request->validate([
            'drive' => ['required', 'string', 'regex:/^[A-Za-z]:?$/'],
        ], ['drive.required' => 'Choose a USB drive.', 'drive.regex' => 'Choose a USB drive.'])['drive'];

        set_time_limit(0);

        try {
            $done = $this->usb->copyLatestIfIdle($drive, $request->user());
        } catch (UsbCopyFailed $e) {
            return $this->toast('error', $e->getMessage());
        }

        if ($done === null) {
            return $this->toast('error', 'A backup or a test restore is running. Try again in a few minutes.');
        }

        return $this->toast('success', $done['message']);
    }

    /**
     * Test the latest backup now: load it into the scratch database and
     * compare the counts. One at a time: it shares the backup lock.
     */
    public function testRestore(Request $request, TestRestoreService $tests): RedirectResponse
    {
        Gate::authorize(Permission::ViewBackups->value);

        set_time_limit(0);

        $run = $tests->runIfIdle('manual', $request->user());

        if ($run === null) {
            return $this->toast('error', 'A backup or a test restore is already running.');
        }

        if ($run->status === BackupStatus::Failed) {
            return $this->toast('error', 'Test restore failed: '.$this->monitor->shortError($run->message));
        }

        return $this->toast('success', 'Test restore passed: the latest backup loads and the record counts match.');
    }

    /**
     * Mark the weekly USB copy or the monthly test restore as done. Only a
     * reminder: it blocks nothing.
     */
    public function mark(string $type): RedirectResponse
    {
        Gate::authorize(Permission::ViewBackups->value);

        $mark = BackupMarkType::fromSlug($type) ?? abort(404);

        $row = BackupMark::create([
            'type' => $mark,
            'marked_by' => $this->userId(),
            'marked_at' => now(),
        ]);

        $this->audit->log($mark->auditAction(), $row, ['label' => $mark->label()]);

        return $this->toast('success', "{$mark->label()} marked as done.");
    }

    /**
     * @return array{type: string, label: string, last_at: ?string, by: ?string, overdue: bool}
     */
    private function markRow(BackupMarkType $type): array
    {
        $state = $this->monitor->mark($type);

        return [
            'type' => $type->slug(),
            'label' => $type->label(),
            'last_at' => $state['last']?->marked_at->toIso8601String(),
            'by' => BackupMonitor::markedBy($state['last']),
            'overdue' => $state['overdue'],
        ];
    }

    /**
     * @return array{at: string, result: string, trigger: string, error: string|null}|null
     */
    private function testRestoreInfo(): ?array
    {
        $last = $this->monitor->lastTestRestore();

        if ($last === null) {
            return null;
        }

        return [
            'at' => $last->started_at->toIso8601String(),
            'result' => $last->status->value,
            'trigger' => $last->trigger,
            'error' => $last->status === BackupStatus::Failed ? $this->monitor->shortError($last->message) : null,
        ];
    }

    private function megabytes(?int $bytes): ?float
    {
        return $bytes === null ? null : max(0.01, round($bytes / 1048576, 2));
    }

    private function userId(): int
    {
        return (int) auth()->id();
    }

    private function toast(string $type, string $message): RedirectResponse
    {
        Inertia::flash('toast', ['type' => $type, 'message' => $message]);

        return back();
    }
}
