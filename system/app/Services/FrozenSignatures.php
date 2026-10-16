<?php

namespace App\Services;

use Illuminate\Support\Facades\Storage;

/**
 * The signature block of a closed month (or of a quarter or year whose months are all closed), frozen at close: the Prepared by and
 * Noted by names and titles, and a copy of the two signature pictures. It is
 * saved with the month's report snapshot, so a closed month keeps showing
 * the signatures it was closed with, even after Settings change.
 *
 * The picture copies live on the private "local" disk, in one folder per
 * month, quarter or year (frozen-signatures/2026-09/, 2026-Q3/, 2026/), never in public/. They are served only
 * by SignatureController (signed-in Admin or Viewer). Reopening the month
 * removes the folder together with the snapshot.
 */
class FrozenSignatures
{
    private const FOLDER = 'frozen-signatures';

    /** The two people, and the file name of each picture (the extension is png or jpg). */
    public const WHO = ['prepared', 'noted'];

    public function __construct(private readonly HospitalSettings $hospital) {}

    /**
     * Copy the current signature block for `$period` and return what to save
     * with the snapshot. Anything left from an earlier close is removed first.
     *
     * @return array{prepared_by: string, prepared_by_title: string, noted_by: string, noted_by_title: string, prepared_signature: string|null, noted_signature: string|null}
     */
    public function freeze(string $period): array
    {
        $this->release($period);

        $values = $this->hospital->values();
        $block = [
            'prepared_by' => $values['prepared_by'],
            'prepared_by_title' => $values['prepared_by_title'],
            'noted_by' => $values['noted_by'],
            'noted_by_title' => $values['noted_by_title'],
            'prepared_signature' => null,
            'noted_signature' => null,
        ];

        $disk = Storage::disk('local');

        foreach (self::WHO as $who) {
            $source = $this->hospital->imageFile("{$who}_signature");

            if ($source === null) {
                continue;
            }

            $name = $who.'.'.pathinfo($source, PATHINFO_EXTENSION);
            $disk->put($this->path($period, $name), $disk->get($source));

            $block["{$who}_signature"] = $name;
        }

        return $block;
    }

    /** Remove the frozen pictures of a month. */
    public function release(string $period): void
    {
        Storage::disk('local')->deleteDirectory(self::FOLDER.'/'.$period);
    }

    /**
     * The path of a frozen picture on the private disk, or null when there is
     * none. Only the two names this class writes are ever accepted.
     *
     * @param  array<string, string|null>  $block
     */
    public function file(string $period, string $who, array $block): ?string
    {
        $name = $block["{$who}_signature"] ?? null;

        if (! in_array($name, ["{$who}.png", "{$who}.jpg"], true)) {
            return null;
        }

        $path = $this->path($period, $name);

        return Storage::disk('local')->exists($path) ? $path : null;
    }

    /**
     * The address of a frozen picture for the page, or null.
     *
     * @param  array<string, string|null>  $block
     */
    public function url(string $period, string $who, array $block): ?string
    {
        $path = $this->file($period, $who, $block);

        return $path === null
            ? null
            : route('reports.signature', ['span' => $period, 'who' => $who, 'v' => Storage::disk('local')->lastModified($path)], false);
    }

    /**
     * A frozen picture as a data address for the PDF, or null.
     *
     * @param  array<string, string|null>  $block
     */
    public function dataUri(string $period, string $who, array $block): ?string
    {
        $path = $this->file($period, $who, $block);

        return $path === null
            ? null
            : 'data:'.$this->hospital->imageMime($path).';base64,'.base64_encode(Storage::disk('local')->get($path));
    }

    private function path(string $period, string $name): string
    {
        return self::FOLDER.'/'.$period.'/'.$name;
    }
}
