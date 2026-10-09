<?php

namespace App\Http\Controllers\Reports;

use App\Http\Controllers\Concerns\StubResponses;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;

/**
 * Close / reopen / delete a month. {period} is "YYYY-MM".
 */
class MonthController extends Controller
{
    use StubResponses;

    /**
     * Close the month (locks it and saves a report snapshot).
     */
    public function close(string $period): RedirectResponse
    {
        return $this->notBuiltYet("Close month {$period}");
    }

    /**
     * Reopen the month (discards the saved snapshot).
     */
    public function reopen(string $period): RedirectResponse
    {
        return $this->notBuiltYet("Reopen month {$period}");
    }

    /**
     * Delete the month report: all its visits go to Trash as one entry.
     */
    public function destroy(string $period): RedirectResponse
    {
        return $this->notBuiltYet("Delete month report {$period}");
    }
}
