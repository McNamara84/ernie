<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\Assistance\IndexAssistanceRequest;
use App\Services\Assistance\AssistanceReviewService;
use App\Services\Assistance\AssistantRegistrar;
use Closure;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Http\JsonResponse;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

final class AssistanceDataController extends Controller
{
    private const int SLOW_REQUEST_THRESHOLD_MS = 1000;

    public function __construct(
        private readonly AssistanceReviewService $reviewService,
        private readonly AssistantRegistrar $registrar,
    ) {}

    public function summary(IndexAssistanceRequest $request): JsonResponse
    {
        return $this->respond(
            request: $request,
            scope: 'summary',
            callback: fn (): array => $this->reviewService->summary($request->resourceImpactFilter()),
        );
    }

    public function all(IndexAssistanceRequest $request): JsonResponse
    {
        return $this->respond(
            request: $request,
            scope: 'all',
            callback: fn (): LengthAwarePaginator => $this->reviewService->paginateAll(
                request: $request,
                perPage: $this->perPage($request),
                filter: $request->resourceImpactFilter(),
            ),
        );
    }

    public function assistant(IndexAssistanceRequest $request, string $assistantId): JsonResponse
    {
        if (! $this->registrar->has($assistantId)) {
            return response()->json(['message' => 'Unknown assistant.'], 404);
        }

        return $this->respond(
            request: $request,
            scope: $assistantId,
            callback: fn (): LengthAwarePaginator => $this->reviewService->paginateAssistant(
                assistantId: $assistantId,
                request: $request,
                perPage: $this->perPage($request),
                filter: $request->resourceImpactFilter(),
            ) ?? throw new \LogicException('Registered assistant could not be loaded.'),
        );
    }

    private function perPage(IndexAssistanceRequest $request): int
    {
        return max(1, min((int) $request->input('per_page', 25), 100));
    }

    /**
     * @param  Closure(): (array<string, mixed>|LengthAwarePaginator<int, array<string, mixed>>)  $callback
     */
    private function respond(IndexAssistanceRequest $request, string $scope, Closure $callback): JsonResponse
    {
        $requestId = $this->requestId($request);
        $startedAt = hrtime(true);
        $queryCount = 0;
        $queryDurationMs = 0.0;
        $filter = $request->resourceImpactFilter();
        $context = [
            'request_id' => $requestId,
            'scope' => $scope,
            'has_doi_filter' => $filter->doi !== null,
            'datacenter_id' => $filter->datacenterId,
            'page' => max(1, (int) $request->input('page', 1)),
            'per_page' => $this->perPage($request),
        ];

        DB::listen(static function (QueryExecuted $query) use (&$queryCount, &$queryDurationMs): void {
            $queryCount++;
            $queryDurationMs += $query->time;
        });

        Log::info('Assistance data request started', $context);

        try {
            $result = $callback();
        } catch (Throwable $exception) {
            $elapsedMs = $this->elapsedMilliseconds($startedAt);
            Log::error('Assistance data request failed', [
                ...$context,
                'duration_ms' => $elapsedMs,
                'query_count' => $queryCount,
                'query_duration_ms' => (int) round($queryDurationMs),
                'exception' => $exception::class,
            ]);
            report($exception);

            return response()->json([
                'message' => 'The assistance data could not be loaded.',
                'request_id' => $requestId,
            ], 500)->header('X-Assistance-Request-Id', $requestId);
        }

        $elapsedMs = $this->elapsedMilliseconds($startedAt);
        $completionContext = [
            ...$context,
            'duration_ms' => $elapsedMs,
            'query_count' => $queryCount,
            'query_duration_ms' => (int) round($queryDurationMs),
            'result_count' => $this->resultCount($result),
        ];

        if ($elapsedMs >= self::SLOW_REQUEST_THRESHOLD_MS) {
            Log::warning('Assistance data request completed slowly', $completionContext);
        } else {
            Log::info('Assistance data request completed', $completionContext);
        }

        return response()->json($result)
            ->header('X-Assistance-Request-Id', $requestId);
    }

    private function requestId(IndexAssistanceRequest $request): string
    {
        $provided = $request->header('X-Assistance-Request-Id');

        return is_string($provided) && Str::isUuid($provided)
            ? $provided
            : Str::uuid()->toString();
    }

    private function elapsedMilliseconds(int $startedAt): int
    {
        return (int) round((hrtime(true) - $startedAt) / 1_000_000);
    }

    /**
     * @param  array<string, mixed>|LengthAwarePaginator<int, array<string, mixed>>  $result
     */
    private function resultCount(array|LengthAwarePaginator $result): int
    {
        if ($result instanceof LengthAwarePaginator) {
            return $result->count();
        }

        $counts = $result['pendingCounts'] ?? [];

        return is_array($counts) ? array_sum(array_map('intval', $counts)) : count($result);
    }
}
