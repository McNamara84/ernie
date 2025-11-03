<?php

namespace App\Http\Controllers;

use App\Models\License;
use Illuminate\Http\JsonResponse;

class LicenseController extends Controller
{
    /**
     * Return all licenses.
     */
    public function index(): JsonResponse
    {
        $licenses = License::query()
            ->orderByName()
            ->get(['id', 'identifier', 'name']);

        return response()->json($licenses);
    }

    /**
     * Return all licenses that are active for ELMO.
     */
    public function elmo(): JsonResponse
    {
        $licenses = License::query()
            ->active()
            ->elmoActive()
            ->orderByName()
            ->get(['id', 'identifier', 'name']);

        return response()->json($licenses);
    }

    /**
     * Return all licenses that are active for Ernie.
     */
    public function ernie(): JsonResponse
    {
        $licenses = License::query()
            ->active()
            ->orderByUsageCount()
            ->get(['id', 'identifier', 'name']);

        return response()->json($licenses);
    }
}
