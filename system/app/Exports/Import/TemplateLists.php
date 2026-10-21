<?php

namespace App\Exports\Import;

use App\Enums\Category;
use App\Enums\Sex;
use App\Models\Branch;
use App\Models\Diagnosis;
use App\Models\Rank;

/**
 * The dropdown lists copied into the downtime template, as they are right
 * now: only active items (branches switched on, ranks and diagnoses not in
 * Trash), ranks in seniority order, "Other (specify)" last.
 */
final class TemplateLists
{
    /**
     * @param  list<string>  $sexes
     * @param  list<string>  $categories
     * @param  list<string>  $branches  Branch names
     * @param  array<string, list<string>>  $ranksByBranch  Branch name => ranks / types in order
     * @param  list<string>  $ranks  Every rank / type name once, branch by branch
     * @param  list<string>  $diagnoses  Alphabetical, "Other (specify)" last
     */
    public function __construct(
        public readonly array $sexes,
        public readonly array $categories,
        public readonly array $branches,
        public readonly array $ranksByBranch,
        public readonly array $ranks,
        public readonly array $diagnoses,
    ) {}

    public static function current(): self
    {
        $ranksByBranch = [];

        $branches = Branch::query()->where('is_active', true)->ordered()->get();

        foreach ($branches as $branch) {
            $ranksByBranch[$branch->name] = Rank::query()
                ->where('branch_id', $branch->id)
                ->ordered()
                ->pluck('name')
                ->all();
        }

        return new self(
            sexes: Sex::values(),
            categories: Category::values(),
            branches: $branches->pluck('name')->all(),
            ranksByBranch: $ranksByBranch,
            ranks: array_values(array_unique(array_merge(...array_values($ranksByBranch) ?: [[]]))),
            diagnoses: Diagnosis::query()->ordered()->pluck('name')->all(),
        );
    }
}
