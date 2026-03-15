<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\DescriptionType;
use Illuminate\Http\JsonResponse;

class DescriptionTypeController extends Controller
{
    /**
     * Return all description types.
     */
    public function index(): JsonResponse
    {
        $types = DescriptionType::query()
            ->orderBy('name')
            ->get(['id', 'name', 'slug']);

        return response()->json($types);
    }

    /**
     * Return all description types that are active for ELMO.
     */
    public function elmo(): JsonResponse
    {
        $types = DescriptionType::query()
            ->active()
            ->elmoActive()
            ->orderBy('name')
            ->get(['id', 'name', 'slug']);

        return response()->json($types);
    }

    /**
     * Return all description types that are active for ERNIE.
     */
    public function ernie(): JsonResponse
    {
        $types = DescriptionType::query()
            ->active()
            ->orderBy('name')
            ->get(['id', 'name', 'slug']);

        return response()->json($types);
    }
}
