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
     * Maximum allowed entries per page to prevent memory exhaustion.
     */
    private const MAX_PER_PAGE = 200;

    /**
     * Display the logs page.
     */
    public function index(Request $request): Response
    {
        $perPage = min((int) $request->input('per_page', 50), self::MAX_PER_PAGE);
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
        $perPage = min((int) $request->input('per_page', 50), self::MAX_PER_PAGE);
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

        // Validate line_number is a positive integer
        if (! is_numeric($lineNumber) || (int) $lineNumber < 1) {
            return response()->json(['error' => 'Invalid log entry: line_number must be a positive integer'], 400);
        }

        // Validate timestamp exists and matches Laravel log format (YYYY-MM-DD HH:MM:SS)
        if (! $timestamp || ! preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $timestamp)) {
            return response()->json(['error' => 'Invalid log entry: timestamp must be in format YYYY-MM-DD HH:MM:SS'], 400);
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
        $success = $this->logService->clearLogs();

        if (! $success) {
            return response()->json(['error' => 'Failed to clear logs. Please try again.'], 500);
        }

        return response()->json(['message' => 'All logs cleared successfully']);
    }
}
