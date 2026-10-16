<?php

namespace App\Services;

use App\Models\Setting;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/**
 * The hospital details of the settings table: name, office line, the two
 * signatories (with their signature pictures) and the logo. Used by the
 * Settings screen and by every report (screen, Print, PDF, Excel), so the
 * report and the form agree on what an empty value means: the default below.
 *
 * The pictures (logo and the two signatures) are kept on the private "local"
 * disk (storage/app/private), never in public/, under a fixed name (e.g.
 * logo.png or logo.jpg) so the name of the uploaded file is never used. They
 * are only served by HospitalController.
 */
class HospitalSettings
{
    public const DEFAULT_NAME = '[Hospital Name]';

    /** What an empty value means, for the keys that have a default. */
    public const DEFAULTS = [
        'report_office' => 'Admission / Administrative Office',
        'prepared_by_title' => 'Admission / Admin Office',
        'noted_by_title' => 'Commanding Officer',
    ];

    /** The six keys shown on the form and on the report sheet. */
    public const KEYS = ['hospital_name', 'report_office', 'prepared_by', 'prepared_by_title', 'noted_by', 'noted_by_title'];

    /**
     * The pictures: the setting that holds each file name, and the base of
     * that file name (the file is <base>.png or <base>.jpg).
     */
    public const IMAGES = [
        'logo' => ['setting' => 'logo_path', 'base' => 'logo'],
        'prepared_signature' => ['setting' => 'prepared_by_signature', 'base' => 'signature-prepared'],
        'noted_signature' => ['setting' => 'noted_by_signature', 'base' => 'signature-noted'],
    ];

    /**
     * The six values as a report shows them: an empty value becomes its default.
     *
     * @return array{hospital_name: string, report_office: string, prepared_by: string, prepared_by_title: string, noted_by: string, noted_by_title: string}
     */
    public function values(): array
    {
        $stored = Setting::query()->whereIn('key', self::KEYS)->pluck('value', 'key');

        $values = [];

        foreach (self::KEYS as $key) {
            $value = trim((string) $stored->get($key));
            $values[$key] = $value !== '' ? $value : $this->defaultOf($key);
        }

        return $values;
    }

    /**
     * Save the six values (an empty one is saved as its default) and return
     * the ones that changed as [key => [old, new]].
     *
     * @param  array<string, string|null>  $input
     * @return array<string, array{0: string, 1: string}>
     */
    public function save(array $input): array
    {
        $old = $this->values();
        $changed = [];

        foreach (self::KEYS as $key) {
            $new = trim((string) ($input[$key] ?? ''));
            $new = $new !== '' ? $new : $this->defaultOf($key);

            if ($new !== $old[$key]) {
                $changed[$key] = [$old[$key], $new];
            }

            Setting::query()->updateOrCreate(['key' => $key], ['value' => $new]);
        }

        return $changed;
    }

    private function defaultOf(string $key): string
    {
        return self::DEFAULTS[$key] ?? ($key === 'hospital_name' ? self::DEFAULT_NAME : '');
    }

    /** The file name of a picture on the private disk, or null when none is set or the file is gone. */
    public function imageFile(string $image): ?string
    {
        $file = Setting::valueOf(self::IMAGES[$image]['setting']);

        if ($file === null || ! in_array($file, $this->imageFiles($image), true) || ! Storage::disk('local')->exists($file)) {
            return null;
        }

        return $file;
    }

    /**
     * The address of a picture for the page, or null. The number at the end
     * changes with every new picture so a browser never shows an old one.
     */
    public function imageUrl(string $image): ?string
    {
        $file = $this->imageFile($image);

        if ($file === null) {
            return null;
        }

        $version = Storage::disk('local')->lastModified($file);

        return match ($image) {
            'logo' => route('hospital.logo', ['v' => $version], false),
            'prepared_signature' => route('hospital.signature', ['who' => 'prepared', 'v' => $version], false),
            'noted_signature' => route('hospital.signature', ['who' => 'noted', 'v' => $version], false),
        };
    }

    /** A picture as a data address for the PDF (dompdf does not fetch pages), or null. */
    public function imageDataUri(string $image): ?string
    {
        $file = $this->imageFile($image);

        if ($file === null) {
            return null;
        }

        return 'data:'.$this->imageMime($file).';base64,'.base64_encode(Storage::disk('local')->get($file));
    }

    public function imageMime(string $file): string
    {
        return str_ends_with($file, '.png') ? 'image/png' : 'image/jpeg';
    }

    /**
     * Store an uploaded picture (already checked by the form request: real
     * PNG or JPG, size and dimensions) and return its kind, "png" or "jpg".
     */
    public function storeImage(string $image, UploadedFile $upload): string
    {
        // The real type of the file, not the name the browser sent.
        $kind = $upload->getMimeType() === 'image/png' ? 'png' : 'jpg';
        $name = self::IMAGES[$image]['base'].'.'.$kind;

        // The picture is decoded and saved again, never stored as uploaded:
        // this drops anything hidden in the file besides the pixels.
        $data = $this->reencode($image, $upload, $kind);

        $this->deleteImageFiles($image);
        Storage::disk('local')->put($name, $data);

        Setting::query()->updateOrCreate(['key' => self::IMAGES[$image]['setting']], ['value' => $name]);

        return $kind;
    }

    /** The picture decoded and encoded again as PNG (keeping transparency) or JPG. */
    private function reencode(string $image, UploadedFile $upload, string $kind): string
    {
        $source = @imagecreatefromstring((string) file_get_contents((string) $upload->getRealPath()));

        if ($source === false) {
            throw ValidationException::withMessages([$image => 'The picture could not be read. Choose another file.']);
        }

        ob_start();

        if ($kind === 'png') {
            imagealphablending($source, false);
            imagesavealpha($source, true);
            imagepng($source, null, 6);
        } else {
            imagejpeg($source, null, 90);
        }

        return (string) ob_get_clean();
    }

    /** Remove a picture. False when there was none. */
    public function removeImage(string $image): bool
    {
        $had = $this->imageFile($image) !== null || (string) Setting::valueOf(self::IMAGES[$image]['setting']) !== '';

        $this->deleteImageFiles($image);
        Setting::query()->updateOrCreate(['key' => self::IMAGES[$image]['setting']], ['value' => '']);

        return $had;
    }

    /** @return list<string> */
    private function imageFiles(string $image): array
    {
        return [self::IMAGES[$image]['base'].'.png', self::IMAGES[$image]['base'].'.jpg'];
    }

    private function deleteImageFiles(string $image): void
    {
        Storage::disk('local')->delete($this->imageFiles($image));
    }

    /** The logo, for the callers that only need that one. */
    public function logoDataUri(): ?string
    {
        return $this->imageDataUri('logo');
    }
}
