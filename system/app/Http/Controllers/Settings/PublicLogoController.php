<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Services\HospitalSettings;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The hospital logo for the login page, where nobody is signed in yet.
 *
 * Public, read-only and cached. It serves ONLY the logo file: there is no
 * parameter that picks a file, and the signatures are never reachable from
 * here (they stay behind sign-in in HospitalController). 404 when no logo is
 * set. The address carries ?v=<file time>, so a new logo is never hidden
 * behind an old cached one.
 */
class PublicLogoController extends Controller
{
    public function __invoke(HospitalSettings $settings): StreamedResponse
    {
        $file = $settings->imageFile('logo') ?? abort(404);

        return Storage::disk('local')->response($file, null, [
            'Content-Type' => $settings->imageMime($file),
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; img-src 'self'",
            'Cache-Control' => 'public, max-age=86400',
        ]);
    }
}
