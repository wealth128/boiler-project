<?php

namespace App\Services\Import;

use App\Models\ImportBatch;

/**
 * What a confirm did: the saved batch (null when no row could be saved), and
 * the rows that were left out because a possible duplicate visit showed up
 * after the preview.
 */
final class ImportOutcome
{
    /**
     * @param  list<int>  $heldBack  File row numbers
     */
    public function __construct(
        public readonly ?ImportBatch $batch,
        public readonly array $heldBack = [],
    ) {}
}
