<?php

namespace App\Http\Controllers\Encode;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PatientSearchController extends Controller
{
    /**
     * JSON: returning-patient search by name or Patient ID (?q=).
     * The only JSON endpoint in the app. Stub: always returns no matches.
     */
    public function __invoke(Request $request): JsonResponse
    {
        return response()->json([
            'query' => (string) $request->query('q', ''),
            'data' => [],
        ]);
    }
}
