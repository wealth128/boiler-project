<?php

namespace App\Http\Requests\Encode;

use App\Models\Visit;
use Illuminate\Support\Facades\Gate;

/**
 * "Save changes" on a visit. VisitPolicy decides who may edit it: the
 * encoder who saved it, on the same day; Admin, any visit; nobody while
 * its month is closed. A denial shows the 403 page with the reason.
 */
class UpdateVisitRequest extends VisitRequest
{
    public function authorize(): bool
    {
        Gate::authorize('update', $this->editedVisit());

        return true;
    }

    protected function editedVisit(): Visit
    {
        /** @var Visit $visit */
        $visit = $this->route('visit');

        return $visit;
    }
}
