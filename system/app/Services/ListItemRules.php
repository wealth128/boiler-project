<?php

namespace App\Services;

use App\Models\AgeBracket;
use App\Models\Diagnosis;
use App\Models\Rank;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * The dropdown list rules (planning/mvp-scope.md, "Dropdown lists"), in one
 * place for the Lists screen (App\Http\Requests\Settings\ListItemRequest)
 * and for restoring from Trash (TrashService).
 *
 * - Names are trimmed with inner spaces collapsed: 2 to 120 characters for
 *   diagnoses, 2 to 60 for ranks.
 * - No two names alike in one list, ignoring upper / lower case. Ranks:
 *   within their branch. A name in Trash counts too, so it is restored
 *   instead of added again.
 * - Age brackets: ages 0 to 120, From not above To, null To = "and above",
 *   and no two active brackets share an age.
 *
 * Lists are short (tens to a few hundred items), so names are compared in
 * PHP. That also catches names saved before these rules (double spaces).
 */
class ListItemRules
{
    public const NAME_MIN = 2;

    public const DIAGNOSIS_NAME_MAX = 120;

    public const RANK_NAME_MAX = 60;

    public const AGE_MIN = 0;

    public const AGE_MAX = 120;

    public const FIXED_MESSAGE = '"Other (specify)" is a fixed entry and can\'t be changed.';

    /**
     * " Acute   fever " becomes "Acute fever".
     */
    public static function normalizeName(string $name): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $name));
    }

    public static function sameName(string $a, string $b): bool
    {
        return mb_strtolower(self::normalizeName($a)) === mb_strtolower(self::normalizeName($b));
    }

    /**
     * A diagnosis with the same name, active ones first, then ones in Trash.
     */
    public static function duplicateDiagnosis(string $name, ?int $exceptId = null, bool $includeTrash = true): ?Diagnosis
    {
        /** @var Diagnosis|null */
        return self::duplicate(Diagnosis::query(), $name, $exceptId, $includeTrash);
    }

    /**
     * A rank of the same branch with the same name, active ones first.
     */
    public static function duplicateRank(int $branchId, string $name, ?int $exceptId = null, bool $includeTrash = true): ?Rank
    {
        /** @var Rank|null */
        return self::duplicate(Rank::query()->where('branch_id', $branchId), $name, $exceptId, $includeTrash);
    }

    /**
     * "“Fever” is already in the list." or, when the match is in Trash,
     * "“Fever” is in Trash. Restore it from Trash instead."
     */
    public static function duplicateMessage(Diagnosis|Rank $match): string
    {
        if ($match->trashed()) {
            return "“{$match->name}” is in Trash. Restore it from Trash instead.";
        }

        return $match instanceof Rank
            ? "“{$match->name}” is already in the {$match->branch->name} list."
            : "“{$match->name}” is already in the list.";
    }

    /**
     * The first active bracket that shares an age with min..max.
     */
    public static function overlappingBracket(int $min, ?int $max, ?int $exceptId = null): ?AgeBracket
    {
        return AgeBracket::query()
            ->when($exceptId !== null, fn (Builder $query) => $query->whereKeyNot($exceptId))
            ->ordered()
            ->get()
            ->first(fn (AgeBracket $bracket): bool => AgeBracket::rangesOverlap($min, $max, $bracket->min_age, $bracket->max_age));
    }

    public static function overlapMessage(AgeBracket $other): string
    {
        return "Overlaps {$other->label}. Adjust the ages.";
    }

    /**
     * Why a list item in Trash can't come back, or null when it can: its
     * name is now used by an active item, or its ages overlap an active
     * bracket. Other records: always null.
     */
    public static function restoreConflict(Model $item): ?string
    {
        if ($item instanceof AgeBracket) {
            $other = self::overlappingBracket($item->min_age, $item->max_age, $item->id);

            return $other === null ? null
                : "Can't restore {$item->label}: it overlaps {$other->label}. Edit or delete that one first.";
        }

        $match = match (true) {
            $item instanceof Diagnosis => self::duplicateDiagnosis($item->name, $item->id, includeTrash: false),
            $item instanceof Rank => self::duplicateRank($item->branch_id, $item->name, $item->id, includeTrash: false),
            default => null,
        };

        return $match === null ? null
            : "Can't restore “{$item->name}”: ".self::duplicateMessage($match).' Rename or delete that one first.';
    }

    /**
     * @param  Builder<Diagnosis>|Builder<Rank>  $query
     */
    private static function duplicate(Builder $query, string $name, ?int $exceptId, bool $includeTrash): ?Model
    {
        if ($includeTrash) {
            $query->withTrashed();
        }

        return $query
            ->when($exceptId !== null, fn (Builder $q) => $q->whereKeyNot($exceptId))
            ->get()
            ->filter(fn (Model $item): bool => self::sameName((string) $item->getAttribute('name'), $name))
            ->sortBy(fn (Model $item): int => $item->getAttribute('deleted_at') === null ? 0 : 1)
            ->first();
    }
}
