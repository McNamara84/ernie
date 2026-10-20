<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\UsageStatisticsExportRequest;
use App\Http\Requests\UsageStatisticsRequest;
use App\Services\Statistics\LegacyStatisticsDashboardService;
use App\Services\Statistics\StatisticsDashboardService;
use App\Services\Usage\CounterReadService;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class StatisticsController extends Controller
{
    public function __construct(
        private readonly StatisticsDashboardService $dashboardService,
    ) {}

    public function index(UsageStatisticsRequest $request): Response
    {
        $mode = $request->string('mode', 'counter')->toString();
        $metric = $request->string('metric', 'unique')->toString();
        $access = $request->string('access_method', 'regular')->toString();
        $payload = $mode === 'legacy'
            ? app(LegacyStatisticsDashboardService::class)->build()
            : $this->dashboardService->build($metric, $access);
        $status = $payload['usageStatus'] ?? app(CounterReadService::class)->status();
        $legacyDates = array_filter([
            DB::table('landing_page_daily_statistics')->min('statistic_date'),
            DB::table('portal_search_daily_statistics')->min('statistic_date'),
        ]);

        return Inertia::render('statistics', [
            ...$payload, 'usageStatus' => $status,
            'filters' => ['mode' => $mode, 'metric' => $metric, 'access_method' => $access],
            'lastUpdated' => $mode === 'legacy' ? $status['startedAt'] : $status['lastAggregatedAt'],
            'legacyPeriod' => ['from' => $legacyDates === [] ? null : min($legacyDates), 'until' => $status['startedAt']],
        ]);
    }

    public function export(UsageStatisticsExportRequest $request): StreamedResponse
    {
        $access = ucfirst($request->string('access_method', 'regular')->toString());
        $from = $request->string('from')->toString();
        $to = $request->string('to')->toString();
        // Spill large exports to a private temporary file. This keeps one
        // published revision without holding a database lock for a slow client.
        $output = fopen('php://temp/maxmemory:2097152', 'w+b');
        if ($output === false) {
            throw new \RuntimeException('Cannot open CSV output.');
        }
        try {
            $dataThrough = app(CounterReadService::class)->snapshot(function () use ($access, $from, $to, $output): ?string {
                $status = app(CounterReadService::class)->status();
                fputcsv($output, ['landing_page_id', 'identifier', 'resource_type', 'month', 'access_method', 'total_investigations', 'unique_investigations', 'total_requests', 'unique_requests'], escape: '');
                $rows = DB::table('usage_monthly_metrics as metrics')
                    ->join('landing_pages', 'landing_pages.id', '=', 'metrics.landing_page_id')
                    ->join('resources', 'resources.id', '=', 'landing_pages.resource_id')
                    ->leftJoin('resource_types', 'resource_types.id', '=', 'resources.resource_type_id')
                    ->where('access_method', $access)
                    ->when($from !== '', fn ($query) => $query->where('month', '>=', $from.'-01'))
                    ->when($to !== '', fn ($query) => $query->where('month', '<=', $to.'-01'))
                    ->select('metrics.*', 'landing_pages.doi_prefix', 'resource_types.slug')
                    ->orderBy('month')->orderBy('landing_page_id')->cursor();
                foreach ($rows as $row) {
                    $safeText = static fn (?string $text): string => preg_match('/^[\s]*[=+\-@]/u', $text ?? '') ? "'".$text : ($text ?? '');
                    $igsn = $row->slug === 'physical-object';
                    fputcsv($output, [
                        $row->landing_page_id, $safeText($row->doi_prefix), $safeText($row->slug),
                        substr($row->month, 0, 7), $row->access_method, $row->total_investigations, $row->unique_investigations,
                        $igsn ? '' : $row->total_requests, $igsn ? '' : $row->unique_requests,
                    ], escape: '');
                }

                return $status['dataThrough'];
            });
            rewind($output);
        } catch (\Throwable $exception) {
            fclose($output);
            throw $exception;
        }
        $filename = 'usage-'.($from ?: 'start').'-'.($to ?: 'latest').'-through-'.($dataThrough ?? 'pending').'.csv';

        return response()->streamDownload(function () use ($output): void {
            try {
                fpassthru($output);
            } finally {
                fclose($output);
            }
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8', 'Cache-Control' => 'private, no-store']);
    }
}
