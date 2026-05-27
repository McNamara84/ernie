<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\Statistics\StatisticsDashboardService;
use Inertia\Inertia;
use Inertia\Response;

class StatisticsController extends Controller
{
    public function __construct(
        private readonly StatisticsDashboardService $dashboardService,
    ) {}

    public function index(): Response
    {
        return Inertia::render('statistics', [
            ...$this->dashboardService->build(),
            'lastUpdated' => now()->toIso8601String(),
        ]);
    }
}