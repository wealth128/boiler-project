<?php

namespace App\Http\Controllers\Backup;

use App\Http\Controllers\Concerns\StubResponses;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Inertia\Response;

class BackupController extends Controller
{
    use StubResponses;

    /**
     * Last backup status.
     */
    public function index(): Response
    {
        return $this->placeholder('Backups', 'Last backup status and history.');
    }

    /**
     * Run a backup now.
     */
    public function run(): RedirectResponse
    {
        return $this->notBuiltYet('Run backup');
    }
}
