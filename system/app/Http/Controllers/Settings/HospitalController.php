<?php

namespace App\Http\Controllers\Settings;

use App\Enums\Permission;
use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\UpdateHospitalRequest;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\HospitalSettings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Hospital settings: name, office line, report signatories (with signature
 * pictures) and logo (Admin only: role:admin on the routes and the
 * "manage-settings" Gate here).
 *
 * Every save is written to the audit log with old and new values per changed
 * field, and a logo or signature change or removal as its own entry (no
 * picture data in the log). The log subject is the signed-in Admin: the
 * settings table keys are words, not IDs.
 *
 * The logo and the two signature pictures are served only by logo() and
 * signature(), for a signed-in Admin or Viewer (the CO sees them on the
 * report); they are never under public/.
 */
class HospitalController extends Controller
{
    private const LABEL = 'Hospital settings';

    private const WHO = ['prepared_signature' => 'Prepared by', 'noted_signature' => 'Noted by'];

    public function __construct(
        private readonly HospitalSettings $settings,
        private readonly AuditLogger $audit,
    ) {}

    public function edit(): Response
    {
        Gate::authorize(Permission::ManageSettings->value);

        return Inertia::render('settings/hospital', [
            'values' => $this->settings->values(),
            'defaults' => ['hospital_name' => HospitalSettings::DEFAULT_NAME, ...HospitalSettings::DEFAULTS],
            'logo_url' => $this->settings->imageUrl('logo'),
            'prepared_signature_url' => $this->settings->imageUrl('prepared_signature'),
            'noted_signature_url' => $this->settings->imageUrl('noted_signature'),
            'limits' => [
                'logo_max_kb' => UpdateHospitalRequest::LOGO_MAX_KB,
                'logo_max_pixels' => UpdateHospitalRequest::LOGO_MAX_PIXELS,
            ],
        ]);
    }

    public function update(UpdateHospitalRequest $request): RedirectResponse
    {
        $changed = $this->settings->save($request->safe()->only(HospitalSettings::KEYS));

        if ($changed !== []) {
            $this->audit->log('settings.updated', $this->actor(), [
                'label' => self::LABEL,
                'old' => array_map(fn (array $pair): string => $pair[0], $changed),
                'new' => array_map(fn (array $pair): string => $pair[1], $changed),
            ]);
        }

        foreach (array_keys(HospitalSettings::IMAGES) as $image) {
            $upload = $request->file($image);

            if ($upload === null) {
                continue;
            }

            $kind = $this->settings->storeImage($image, $upload);

            // Only the kind and size: never the picture.
            $this->audit->log($image === 'logo' ? 'settings.logo_changed' : 'settings.signature_changed', $this->actor(), [
                'label' => self::LABEL,
                ...($image === 'logo' ? [] : ['signature' => self::WHO[$image]]),
                'file_type' => strtoupper($kind),
                'size_kb' => (int) ceil($upload->getSize() / 1024),
            ]);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Settings saved.']);

        return to_route('hospital.edit');
    }

    public function destroyLogo(): RedirectResponse
    {
        Gate::authorize(Permission::ManageSettings->value);

        if ($this->settings->removeImage('logo')) {
            $this->audit->log('settings.logo_removed', $this->actor(), ['label' => self::LABEL]);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Logo removed.']);

        return back();
    }

    /** Remove a signature picture. $who is "prepared" or "noted" (the route allows only those). */
    public function destroySignature(string $who): RedirectResponse
    {
        Gate::authorize(Permission::ManageSettings->value);

        $image = "{$who}_signature";

        if ($this->settings->removeImage($image)) {
            $this->audit->log('settings.signature_removed', $this->actor(), ['label' => self::LABEL, 'signature' => self::WHO[$image]]);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Signature removed.']);

        return back();
    }

    /** The logo picture, from the private disk. 404 when none is set. */
    public function logo(): StreamedResponse
    {
        return $this->picture('logo');
    }

    /** A signature picture ("prepared" or "noted"). Same rules as the logo. */
    public function signature(string $who): StreamedResponse
    {
        return $this->picture("{$who}_signature");
    }

    private function picture(string $image): StreamedResponse
    {
        Gate::authorize(Permission::ViewReports->value);

        $file = $this->settings->imageFile($image) ?? abort(404);

        return Storage::disk('local')->response($file, null, [
            'Content-Type' => $this->settings->imageMime($file),
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; img-src 'self'",
            // The logo's address changes with every new logo (?v=), so the browser may keep it
            // for a day instead of downloading it again on every page load. Signatures are
            // never kept.
            'Cache-Control' => $image === 'logo' ? 'private, max-age=86400' : 'private, no-cache',
        ]);
    }

    private function actor(): User
    {
        /** @var User */
        return auth()->user();
    }
}
