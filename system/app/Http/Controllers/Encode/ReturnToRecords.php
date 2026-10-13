<?php

namespace App\Http\Controllers\Encode;

use App\Enums\Permission;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

/**
 * Edit from Patient Records opens /encode?edit={visit}&return={records URL}.
 * "Save changes" and "Cancel edit" then go back to that URL, with the same
 * filters and page.
 *
 * Only a Patient Records visits URL on this site is accepted, and only for
 * a user who may see Records. Anything else is ignored, so the link can't
 * send anyone to another site.
 */
final class ReturnToRecords
{
    private const PATH = '/records/visits';

    public static function from(mixed $url, ?User $user): ?string
    {
        if (! is_string($url) || $url === '' || $user === null) {
            return null;
        }

        if (Gate::forUser($user)->denies(Permission::ViewRecords->value)) {
            return null;
        }

        $parts = parse_url($url);

        if (
            $parts === false
            || isset($parts['scheme'])
            || isset($parts['host'])
            || isset($parts['user'])
            || isset($parts['fragment'])
            || ($parts['path'] ?? '') !== self::PATH
        ) {
            return null;
        }

        return isset($parts['query']) && $parts['query'] !== ''
            ? self::PATH.'?'.$parts['query']
            : self::PATH;
    }
}
