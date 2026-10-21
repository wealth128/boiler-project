<?php

namespace App\Services\Import;

use App\Models\Patient;
use Carbon\CarbonImmutable;

/**
 * What the check found for one row of the file: OK, Warning or Error, the
 * reasons in plain words, and (when the row can be imported) what to save.
 * Made by ImportAnalyzer; the preview page shows toPage(), the import saves
 * from the plan.
 */
final class ImportRowResult
{
    public const OK = 'ok';

    public const WARNING = 'warning';

    public const ERROR = 'error';

    // Warning codes. The page and the import treat these differently.
    public const LINK = 'link';

    public const DUPLICATE_VISIT = 'duplicate_visit';

    public const FILE_DUPLICATE = 'file_duplicate';

    public const COUNTS_AS = 'counts_as';

    public const DIFFERS = 'differs';

    /** @var list<string> Why the row can't be imported. */
    public array $errors = [];

    /** @var list<array{code: string, text: string}> */
    public array $warnings = [];

    /** @var array<string, string> What the preview table shows, as text. */
    public array $display = [];

    // --- The plan: set only when the row has no errors -----------------------

    public ?CarbonImmutable $visitedAt = null;

    /** @var array<string, mixed>|null last_name, first_name, middle_initial, sex, birthdate */
    public ?array $patientAttributes = null;

    /** @var array<string, mixed>|null The visit's fields (age, branch, rank, diagnosis, category, remarks) */
    public ?array $visitAttributes = null;

    public ?string $writtenBy = null;

    /** The patient the row is for: the one named by its Patient ID, or the one that matches by name and birthdate. */
    public ?Patient $patient = null;

    /** True when the row names its patient with a Patient ID. */
    public bool $byPatientNo = false;

    /** True when Admin chose "save as a new patient instead" for this row. */
    public bool $saveAsNew = false;

    public function __construct(public readonly int $number) {}

    public function addError(string $text): void
    {
        $this->errors[] = $text;
    }

    public function addWarning(string $code, string $text): void
    {
        $this->warnings[] = ['code' => $code, 'text' => $text];
    }

    public function hasWarning(string $code): bool
    {
        foreach ($this->warnings as $warning) {
            if ($warning['code'] === $code) {
                return true;
            }
        }

        return false;
    }

    public function status(): string
    {
        return match (true) {
            $this->errors !== [] => self::ERROR,
            $this->warnings !== [] => self::WARNING,
            default => self::OK,
        };
    }

    public function isError(): bool
    {
        return $this->errors !== [];
    }

    /**
     * Ticked in the preview when it opens: OK rows and warnings, except a
     * possible duplicate (of a visit on file, or of an earlier row), which
     * Admin has to tick on purpose. Error rows can never be ticked.
     */
    public function selectedByDefault(): bool
    {
        return ! $this->isError()
            && ! $this->hasWarning(self::DUPLICATE_VISIT)
            && ! $this->hasWarning(self::FILE_DUPLICATE);
    }

    /**
     * The row for the preview page.
     *
     * @return array<string, mixed>
     */
    public function toPage(): array
    {
        return [
            'number' => $this->number,
            'status' => $this->status(),
            'errors' => $this->errors,
            'warnings' => $this->warnings,
            'display' => $this->display,
            'can_save_as_new' => $this->hasWarning(self::LINK),
            'selected' => $this->selectedByDefault(),
        ];
    }
}
