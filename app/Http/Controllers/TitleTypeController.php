<?php

namespace App\Http\Controllers;

use App\Models\TitleType;
use Illuminate\Http\JsonResponse;

class TitleTypeController extends Controller
{
    /**
     * Return all title types.
     */
    public function index(): JsonResponse
    {
        $types = TitleType::query()
            ->orderByName()
            ->get(['id', 'name', 'slug']);

        return response()->json($types);
    }

    /**
     * Return all title types that are active for ELMO.
     */
    public function elmo(): JsonResponse
    {
        $types = TitleType::query()
            ->active()
            ->elmoActive()
            ->orderByName()
            ->get(['id', 'name', 'slug']);

        return response()->json($types);
    }

    /**
     * Return all title types that are active for Ernie.
     */
    public function ernie(): JsonResponse
    {
        $types = TitleType::query()
            ->active()
            ->orderByName()
            ->get(['id', 'name', 'slug']);

        return response()->json($types);
    }
}
