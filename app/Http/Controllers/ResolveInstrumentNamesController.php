<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\ResolveInstrumentNamesRequest;
use App\Services\InstrumentNameResolutionService;
use Illuminate\Http\JsonResponse;

final class ResolveInstrumentNamesController extends Controller
{
    public function __invoke(ResolveInstrumentNamesRequest $request, InstrumentNameResolutionService $resolver): JsonResponse
    {
        return response()->json(['instruments' => $resolver->resolve($request->validated('instruments'))]);
    }
}
