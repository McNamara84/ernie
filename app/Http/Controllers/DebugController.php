<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;

final class DebugController extends Controller
{
    public function __invoke(): JsonResponse
    {
        return response()->json([
            'message' => 'Laravel is working!',
            'database' => 'Connected',
            'redis' => Cache::get('test') !== null ? 'Available' : 'Testing...',
            'app_key' => config('app.key') ? 'Set' : 'Missing',
            'app_url' => config('app.url'),
            'environment' => app()->environment(),
        ]);
    }
}
