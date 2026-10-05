<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Language;
use Illuminate\Http\JsonResponse;

class LanguageController extends Controller
{
    /**
     * Return all languages.
     */
    public function index(): JsonResponse
    {
        $languages = Language::query()
            ->orderByName()
            ->get(['id', 'code', 'name']);

        return response()->json($languages);
    }

    /**
     * Return all languages that are active for ELMO.
     */
    public function elmo(): JsonResponse
    {
        $languages = Language::query()
            ->elmoActive()
            ->orderByName()
            ->get(['id', 'code', 'name']);

        return response()->json($languages);
    }

    /** Return entries independently enabled for ELMO-MSL. */
    public function elmoMsl(): JsonResponse
    {
        $languages = Language::query()
            ->elmoMslActive()
            ->orderByName()
            ->get(['id', 'code', 'name']);

        return response()->json($languages);
    }

    /**
     * Return all languages that are active for Ernie.
     */
    public function ernie(): JsonResponse
    {
        $languages = Language::query()
            ->active()
            ->orderByName()
            ->get(['id', 'code', 'name']);

        return response()->json($languages);
    }
}
