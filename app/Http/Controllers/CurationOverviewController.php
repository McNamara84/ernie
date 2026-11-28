<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\CurationRequest;
use App\Models\Datacenter;
use App\Models\User;
use App\Services\Curation\CurationOverviewService;
use App\Services\Resources\ResourceListingProjectionRefreshService;
use Illuminate\Http\JsonResponse;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class CurationOverviewController extends Controller
{
    public function index(CurationRequest $request, CurationOverviewService $overview): Response
    {
        /** @var User $user */
        $user = $request->user();
        app(ResourceListingProjectionRefreshService::class)->flushPending();
        $filters = $request->validated();
        $counts = [];
        foreach (['progress', 'review', 'embargo', 'published'] as $tab) {
            $counts[$tab] = $overview->query([...$filters, 'tab' => $tab], $user)->count();
        }

        return Inertia::render('curation', [
            'resources' => $overview->rows($filters, $user)->paginate(50)->withQueryString()->through($overview->row(...)),
            'filters' => $filters, 'counts' => $counts,
            'datacenters' => Datacenter::orderBy('name')->get(['id', 'name']),
            'curators' => User::orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function users(CurationRequest $request): JsonResponse
    {
        return response()->json(User::where('is_active', true)
            ->when(! $request->boolean('mentions'), fn ($q) => $q->where('role', '!=', 'beginner'))
            ->when($request->filled('q'), fn ($q) => $q->where(fn ($search) => $search->where('name', 'like', '%'.$request->string('q').'%')->orWhere('email', 'like', '%'.$request->string('q').'%')))
            ->orderBy('name')->orderBy('id')->limit(100)->get(['id', 'name', 'email']));
    }

    public function export(CurationRequest $request, CurationOverviewService $overview): StreamedResponse
    {
        /** @var User $user */
        $user = $request->user();
        app(ResourceListingProjectionRefreshService::class)->flushPending();

        return response()->streamDownload(function () use ($request, $overview, $user): void {
            $stream = fopen('php://output', 'w');
            if ($stream === false) {
                return;
            }
            fwrite($stream, "\xEF\xBB\xBF");
            fputcsv($stream, ['Title', 'DOI', 'Data centre', 'Contact', 'First saved', 'Status', 'Lead', 'Curators', 'Progress', 'Waiting days', 'Needs help', 'Overdue', 'Deadline', 'Last activity'], escape: '');
            foreach ($overview->rows($request->validated(), $user)->lazy(200) as $resource) {
                $row = $overview->row($resource);
                $members = $resource->curators;
                $cells = [$row['title'], $row['doi'], $row['datacenter'], $row['contact'], $row['created_at'], $row['status'],
                    $members->firstWhere('id', $row['lead_id'])->name ?? 'Unassigned', $members->pluck('name')->implode('; '),
                    $row['progress'].'/13', $row['waiting_days'], $row['needs_help'] ? 'Yes' : 'No', $row['overdue'] ? 'Yes' : 'No', $row['deadline'], $row['last_activity_at']];
                fputcsv($stream, array_map(static function (mixed $value): string {
                    $text = (string) $value;

                    return preg_match('/^[\s]*[=+@\-]/u', $text) === 1 ? "'".$text : $text;
                }, $cells), escape: '');
            }
            fclose($stream);
        }, 'curation.csv', ['Content-Type' => 'text/csv; charset=UTF-8', 'Cache-Control' => 'private, no-store']);
    }
}
