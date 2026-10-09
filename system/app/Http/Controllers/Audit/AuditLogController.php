<?php

namespace App\Http\Controllers\Audit;

use App\Http\Controllers\Concerns\StubResponses;
use App\Http\Controllers\Controller;
use Inertia\Response;

class AuditLogController extends Controller
{
    use StubResponses;

    /**
     * Read-only audit log.
     */
    public function index(): Response
    {
        return $this->placeholder('Audit Log', 'Who did what and when (read-only).');
    }
}
