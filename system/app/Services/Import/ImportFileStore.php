<?php

namespace App\Services\Import;

use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Keeps an uploaded import file between "Upload" (preview) and "Confirm".
 *
 * - Folder: storage/app/private/imports (the private disk: never in public/,
 *   and BackupService leaves it out of the picture zip).
 * - The file is named by a random token, so the original name can't point
 *   anywhere. A small .json note beside it holds who uploaded it, when, and
 *   the original name.
 * - Only the user who uploaded it can open it, for 60 minutes. After Confirm,
 *   Cancel or that hour it is deleted (purgeExpired(), run on every import
 *   page and every 15 minutes by the scheduler, so nothing stays longer than
 *   about 75 minutes).
 * - Confirm claims the file first (claim(): the data file is renamed to .used
 *   in one atomic step), so the same file can't be imported twice. After a
 *   successful import finish() leaves a tiny .done note for the same hour, so
 *   a second click says "already imported".
 * - The checked preview is kept beside the file (.analysis) so paging and
 *   reloads don't read and check the whole file again; it goes with the file.
 */
class ImportFileStore
{
    public const FOLDER = 'imports';

    public const MAX_AGE_MINUTES = 60;

    private const DISK = 'local';

    /** The folder on disk, for the backup to skip. */
    public static function path(): string
    {
        return Storage::disk(self::DISK)->path(self::FOLDER);
    }

    /**
     * Save an uploaded file. The extension is the one the request already
     * checked (xlsx or csv); the original name is only kept for display.
     */
    public function store(UploadedFile $file, string $extension, User $by): StagedImport
    {
        $token = Str::random(40);
        $name = $this->cleanName($file->getClientOriginalName());
        $disk = Storage::disk(self::DISK);

        $disk->putFileAs(self::FOLDER, $file, $token.'.'.$extension);
        $disk->put(self::FOLDER.'/'.$token.'.json', (string) json_encode([
            'user_id' => $by->id,
            'name' => $name,
            'extension' => $extension,
            'created_at' => now()->getTimestamp(),
        ]));

        return new StagedImport($token, $extension, $name);
    }

    /**
     * The waiting file for this token, or null when the token is wrong, it
     * belongs to someone else, or the hour is over (it is deleted then).
     */
    public function find(string $token, User $by): ?StagedImport
    {
        if (preg_match('/^[A-Za-z0-9]{40}$/', $token) !== 1) {
            return null;
        }

        $disk = Storage::disk(self::DISK);
        $note = self::FOLDER.'/'.$token.'.json';

        if (! $disk->exists($note)) {
            return null;
        }

        $data = json_decode((string) $disk->get($note), true);
        $extension = is_array($data) ? ($data['extension'] ?? null) : null;

        if (! is_array($data) || ! in_array($extension, ['xlsx', 'csv'], true) || ($data['user_id'] ?? null) !== $by->id) {
            return null;
        }

        $expired = Carbon::createFromTimestamp((int) ($data['created_at'] ?? 0))->addMinutes(self::MAX_AGE_MINUTES)->isPast();

        if ($expired || ! $disk->exists(self::FOLDER.'/'.$token.'.'.$extension)) {
            $this->delete($token);

            return null;
        }

        return new StagedImport($token, $extension, (string) ($data['name'] ?? 'import'));
    }

    /** The absolute path of the file, for the reader. */
    public function absolutePath(StagedImport $file): string
    {
        return Storage::disk(self::DISK)->path($file->relativePath());
    }

    public function delete(string $token): void
    {
        if (preg_match('/^[A-Za-z0-9]{40}$/', $token) !== 1) {
            return;
        }

        $disk = Storage::disk(self::DISK);
        $disk->delete([
            self::FOLDER.'/'.$token.'.xlsx',
            self::FOLDER.'/'.$token.'.csv',
            self::FOLDER.'/'.$token.'.used',
            self::FOLDER.'/'.$token.'.json',
            self::FOLDER.'/'.$token.'.analysis',
        ]);
    }

    /**
     * Take the file for an import: one atomic rename, so only one confirm can
     * ever get it. False when it is already taken (or gone).
     */
    public function claim(StagedImport $file): bool
    {
        return @rename($this->absolutePath($file), Storage::disk(self::DISK)->path($file->claimedPath()));
    }

    /** Give the file back after a failed import, so Admin can try again. */
    public function unclaim(StagedImport $file): void
    {
        @rename(Storage::disk(self::DISK)->path($file->claimedPath()), $this->absolutePath($file));
    }

    /** The import is saved: remove the file and leave the "already imported" note. */
    public function finish(StagedImport $file): void
    {
        $this->delete($file->token);
        Storage::disk(self::DISK)->put(self::FOLDER.'/'.$file->token.'.done', (string) now()->getTimestamp());
    }

    public function wasImported(string $token): bool
    {
        return preg_match('/^[A-Za-z0-9]{40}$/', $token) === 1
            && Storage::disk(self::DISK)->exists(self::FOLDER.'/'.$token.'.done');
    }

    /**
     * The checked preview of a file (what the page shows), kept so that
     * reloading or paging does not check every row again.
     *
     * @param  array<string, mixed>  $analysis
     */
    public function putAnalysis(StagedImport $file, array $analysis): void
    {
        Storage::disk(self::DISK)->put(self::FOLDER.'/'.$file->token.'.analysis', (string) json_encode($analysis, JSON_INVALID_UTF8_SUBSTITUTE));
    }

    /** Drop the kept preview, so the next page load checks the file again. */
    public function forgetAnalysis(StagedImport $file): void
    {
        Storage::disk(self::DISK)->delete(self::FOLDER.'/'.$file->token.'.analysis');
    }

    /** @return array<string, mixed>|null */
    public function analysis(StagedImport $file): ?array
    {
        $path = self::FOLDER.'/'.$file->token.'.analysis';
        $disk = Storage::disk(self::DISK);

        if (! $disk->exists($path)) {
            return null;
        }

        $data = json_decode((string) $disk->get($path), true);

        return is_array($data) ? $data : null;
    }

    /**
     * Delete every waiting file older than an hour (the scheduler runs this every 15 minutes), and any stray file with
     * no note. Returns how many files were removed.
     */
    public function purgeExpired(): int
    {
        $disk = Storage::disk(self::DISK);
        $removed = 0;

        foreach ($disk->files(self::FOLDER) as $path) {
            if ($disk->lastModified($path) > now()->subMinutes(self::MAX_AGE_MINUTES)->getTimestamp()) {
                continue;
            }

            $disk->delete($path);
            $removed++;
        }

        return $removed;
    }

    /**
     * Just the file's own name: no folders, no control characters, at most
     * 255 characters.
     */
    private function cleanName(string $name): string
    {
        $name = basename(str_replace('\\', '/', $name));
        $name = (string) preg_replace('/[\x00-\x1F\x7F]+/u', '', $name);
        $name = mb_substr(trim($name), 0, 255);

        return $name === '' ? 'import' : $name;
    }
}
