<?php

namespace App\Enums;

/**
 * The reasons offered in the "Move to Trash?" dropdown. There is one enum
 * per record type: VisitDeleteReason, PatientDeleteReason,
 * UserDeleteReason, ListItemDeleteReason and MonthReportDeleteReason.
 * TrashService picks the right one and accepts only its values.
 *
 * Each list ends with "Other (specify)": the user then types the reason
 * (OTHER_MIN to OTHER_MAX characters) and it is saved as "Other: <text>".
 * The chosen text is what delete_reason stores, so Trash and the audit log
 * show it as is.
 */
interface DeleteReason
{
    public const OTHER = 'Other (specify)';

    public const OTHER_MIN = 10;

    public const OTHER_MAX = 200;

    /** Is this the "Other (specify)" choice? */
    public function isOther(): bool;

    /**
     * The dropdown choices, in order, for the page.
     *
     * @return list<array{value: string, other: bool}>
     */
    public static function options(): array;
}
