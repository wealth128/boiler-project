<?php

namespace App\Http\Controllers\Trash;

use App\Models\AgeBracket;
use App\Models\Diagnosis;
use App\Models\MonthClosure;
use App\Models\MonthDeletion;
use App\Models\Patient;
use App\Models\Rank;
use App\Models\User;
use App\Models\Visit;
use App\Policies\TrashPolicy;
use App\Services\TablePaging;
use App\Services\TrashService;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * What the Trash screen shows (prototype/prototype.html, trashView): one
 * page of everything in Trash, newest first, with the text Admin must type
 * to delete each item permanently.
 *
 * Visits that went to Trash with "Delete month report" are not listed on
 * their own. They show inside that one Month report row.
 */
final class TrashRows
{
    /** Name of each type in the Type column. */
    private const LABELS = [
        'visits' => 'Visit',
        'patients' => 'Patient',
        'users' => 'User account',
        'diagnoses' => 'Diagnosis',
        'ranks' => 'Rank / type',
        'age-brackets' => 'Age bracket',
        'months' => 'Month report',
    ];

    /**
     * One page of Trash, newest first. Each entry has a type (the URL type)
     * and an id.
     *
     * @return LengthAwarePaginator<int, object{type: string, id: int|string}>
     */
    public static function page(Request $request): LengthAwarePaginator
    {
        $all = null;

        foreach (TrashService::TYPES as $type => $class) {
            $query = DB::table((new $class)->getTable())->selectRaw('? as type, id, deleted_at', [$type]);

            if ($class === Visit::class) {
                $query->whereNotNull('deleted_at')->whereNull('month_deletion_id');
            } elseif ($class !== MonthDeletion::class) {
                $query->whereNotNull('deleted_at');
            }

            $all = $all === null ? $query : $all->unionAll($query);
        }

        return DB::query()
            ->fromSub($all, 'trash')
            ->orderByDesc('deleted_at')
            ->orderBy('type')
            ->orderByDesc('id')
            ->paginate(TablePaging::perPage($request))
            ->withQueryString();
    }

    /**
     * The rows for one page, in the same order, with one query per type.
     *
     * @param  iterable<object{type: string, id: int|string}>  $entries
     * @return list<array<string, mixed>>
     */
    public static function rows(iterable $entries, User $actor): array
    {
        $entries = collect($entries);
        $models = [];

        foreach ($entries->groupBy('type') as $type => $group) {
            $class = TrashService::TYPES[$type];
            $query = $class === MonthDeletion::class ? $class::query() : $class::onlyTrashed();

            $models[$type] = $query
                ->with($class === Rank::class ? ['branch', 'deletedBy'] : ['deletedBy'])
                // "Still used?" for Delete permanently, for the whole page in one query (TrashPolicy::forceDelete).
                ->when(in_array($class, [Patient::class, Diagnosis::class, Rank::class], true), fn ($q) => $q->withExists(['visits as '.TrashPolicy::HAS_VISITS => fn ($visits) => $visits->withTrashed()]))
                ->when($class === User::class, fn ($q) => $q->withExists([
                    'encodedVisits as '.TrashPolicy::HAS_VISITS => fn ($visits) => $visits->withTrashed(),
                    'auditLogs as '.TrashPolicy::HAS_AUDIT,
                ]))
                ->whereKey($group->pluck('id')->all())
                ->get()
                ->keyBy('id');
        }

        // Each row asks whether its month is closed: the closed months are read once for the page.
        return MonthClosure::remembering(fn (): array => $entries
            ->map(fn (object $entry): ?Model => $models[$entry->type][$entry->id] ?? null)
            ->filter()
            ->map(fn (Model $item): array => self::row($item, $actor))
            ->values()
            ->all());
    }

    /**
     * Text typed to confirm a permanent delete: the Visit No., Patient ID,
     * username, name, bracket label, or the month as YYYY-MM.
     */
    public static function confirmText(Model $item): string
    {
        return match (true) {
            $item instanceof Visit => (string) $item->visit_no,
            $item instanceof Patient => (string) $item->patient_no,
            $item instanceof User => $item->username,
            $item instanceof Diagnosis, $item instanceof Rank => $item->name,
            $item instanceof AgeBracket => $item->label,
            $item instanceof MonthDeletion => $item->period,
            default => (string) $item->getKey(),
        };
    }

    /**
     * Is what was typed the confirm text? Spaces around it don't matter.
     * Dashes count as the same character, so a bracket like "18–25" can be
     * typed with the hyphen on the keyboard.
     */
    public static function matches(mixed $typed, string $expected): bool
    {
        if (! is_string($typed)) {
            return false;
        }

        return self::plain(trim($typed)) === self::plain($expected);
    }

    /**
     * @return array<string, mixed>
     */
    private static function row(Model $item, User $actor): array
    {
        $type = (string) array_search($item::class, TrashService::TYPES, true);
        $verdict = Gate::forUser($actor)->inspect('delete-permanently', $item);

        return [
            'key' => "{$type}-{$item->getKey()}",
            'type' => $type,
            'id' => $item->getKey(),
            'type_label' => self::LABELS[$type],
            'item' => self::label($item),
            'reason' => (string) $item->getAttribute('delete_reason'),
            'deleted_by' => $item->deletedBy?->username ?? '—',
            'deleted_at' => Carbon::parse($item->getAttribute('deleted_at'))->toIso8601String(),
            'confirm_text' => self::confirmText($item),
            // Why "Delete permanently" is not offered, or null when it is.
            'blocked' => $verdict->allowed() ? null : ($verdict->message() ?? "This item can't be deleted permanently."),
        ];
    }

    /**
     * The Item column: "V-000123", "P-000012 · Santos, Maria L.",
     * "CPL (Army)", "18–25", "er.cruz", "September 2026 · 40 visits".
     */
    public static function label(Model $item): string
    {
        return match (true) {
            $item instanceof Patient => "{$item->patient_no} · {$item->full_name}",
            $item instanceof Rank => "{$item->name} ({$item->branch->name})",
            $item instanceof MonthDeletion => Carbon::parse($item->period.'-01')->format('F Y')
                .' · '.number_format($item->visit_count).($item->visit_count === 1 ? ' visit' : ' visits'),
            default => self::confirmText($item),
        };
    }

    private static function plain(string $text): string
    {
        return str_replace(['–', '—', '−'], '-', $text);
    }
}
