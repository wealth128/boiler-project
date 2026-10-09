<?php

namespace App\Http\Controllers\Encode;

use App\Http\Controllers\Concerns\StubResponses;
use App\Http\Controllers\Controller;
use Inertia\Response;

class EncodeController extends Controller
{
    use StubResponses;

    /**
     * Encoding form + "My entries today".
     */
    public function index(): Response
    {
        return $this->placeholder('Encode Patient', 'Encoding form and "My entries today".');
    }
}
