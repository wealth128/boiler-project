<?php

namespace App\Http\Controllers\Reports;

use App\Enums\Permission;
use App\Http\Controllers\Controller;
use App\Models\MonthClosure;
use App\Models\ReportSnapshot;
use App\Services\FrozenSignatures;
use App\Services\HospitalSettings;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The signature pictures frozen when a month (or a quarter or year) was closed (FrozenSignatures).
 * Same rules as the current signature pictures: a signed-in Admin or Viewer
 * (role:admin,viewer on the route plus the view-reports Gate). The files are
 * on the private disk, never under public/. 404 when the month is not
 * closed, the quarter or year has no snapshot, or there is no such picture.
 */
class SignatureController extends Controller
{
    public function show(string $span, string $who, FrozenSignatures $frozen, HospitalSettings $hospital): StreamedResponse
    {
        Gate::authorize(Permission::ViewReports->value);

        // A month must still be closed. A quarter or a year only has a
        // snapshot while all its months are closed (reopening removes it).
        abort_if(preg_match('/^\d{4}-\d{2}$/', $span) === 1 && ! MonthClosure::isClosed($span), 404);

        $block = ReportSnapshot::query()->where('period', $span)->first()?->signature_block ?? abort(404);
        $path = $frozen->file($span, $who, $block) ?? abort(404);

        return Storage::disk('local')->response($path, null, [
            'Content-Type' => $hospital->imageMime($path),
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; img-src 'self'",
            'Cache-Control' => 'private, no-cache',
        ]);
    }
}
