<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\LogService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Controller for viewing and managing Laravel log files.
 * Only accessible to users with 'access-administration' gate permission.
 */
class LogController extends Controller
{
    public function __construct(
        private readonly LogService $logService
    ) {}

    /**
     * Display the logs page.
     */
    public function index(Request $request): Response
    {
        $perPage = (int) $request->input('per_page', 50);
        $page = (int) $request->input('page', 1);
        $level = $request->input('level');
        $search = $request->input('search');

        $logs = $this->logService->getLogs(
            perPage: $perPage,
            page: $page,
            level: $level,
            search: $search
        );

        return Inertia::render('Logs/Index', [
            'logs' => $logs['data'],
            'pagination' => [
                'current_page' => $logs['current_page'],
                'last_page' => $logs['last_page'],
                'per_page' => $logs['per_page'],
                'total' => $logs['total'],
            ],
            'filters' => [
                'level' => $level,
                'search' => $search,
                'per_page' => $perPage,
            ],
            'available_levels' => $this->logService->getAvailableLevels(),
            'can_delete' => Gate::allows('delete-logs'),
        ]);
    }

    /**
     * Get logs as JSON (for AJAX requests).
     */
    public function getLogsJson(Request $request): JsonResponse
    {
        $perPage = (int) $request->input('per_page', 50);
        $page = (int) $request->input('page', 1);
        $level = $request->input('level');
        $search = $request->input('search');

        $logs = $this->logService->getLogs(
            perPage: $perPage,
            page: $page,
            level: $level,
            search: $search
        );

        return response()->json($logs);
    }

    /**
     * Delete a specific log entry.
     * Only admins can delete logs (enforced by 'can:delete-logs' route middleware).
     */
    public function destroy(Request $request): JsonResponse
    {
        $lineNumber = $request->input('line_number');
        $timestamp = $request->input('timestamp');

        if (! is_numeric($lineNumber) || (int) $lineNumber < 1 || ! $timestamp) {
            return response()->json(['error' => 'Invalid log entry: line_number must be a positive integer and timestamp is required'], 400);
        }

        $deleted = $this->logService->deleteLogEntry((int) $lineNumber, $timestamp);

        if (! $deleted) {
            return response()->json(['error' => 'Log entry not found or could not be deleted'], 404);
        }

        return response()->json(['message' => 'Log entry deleted successfully']);
    }

    /**
     * Clear all logs.
     * Only admins can clear logs (enforced by 'can:delete-logs' route middleware).
     */
    public function clear(): JsonResponse
    {
        $this->logService->clearLogs();

        return response()->json(['message' => 'All logs cleared successfully']);
    }
}
