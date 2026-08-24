<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\DataCiteUrlUpdateItemStatus;
use App\Http\Requests\DataCiteUrlUpdateItemsRequest;
use App\Http\Requests\DataCiteUrlUpdateScopeRequest;
use App\Models\DataCiteUrlUpdateItem;
use App\Models\DataCiteUrlUpdateRun;
use App\Models\User;
use App\Services\DataCiteUrlUpdatePreviewService;
use App\Services\DataCiteUrlUpdateRunPresenter;
use App\Services\DataCiteUrlUpdateRunService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class DataCiteUrlUpdateController extends Controller
{
    public function __construct(
        private readonly DataCiteUrlUpdatePreviewService $previewService,
        private readonly DataCiteUrlUpdateRunService $runService,
        private readonly DataCiteUrlUpdateRunPresenter $presenter,
    ) {}

    public function preview(DataCiteUrlUpdateScopeRequest $request): JsonResponse
    {
        return response()->json($this->previewService->build($request->scope()));
    }

    public function store(DataCiteUrlUpdateScopeRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $run = $this->runService->start($request->scope(), $user);

        return response()->json(['run' => $this->presenter->run($run)], 202);
    }

    public function show(DataCiteUrlUpdateRun $run): JsonResponse
    {
        Gate::authorize('update-datacite-landing-page-urls');

        return response()->json(['run' => $this->presenter->run($run->refresh())]);
    }

    public function items(DataCiteUrlUpdateItemsRequest $request, DataCiteUrlUpdateRun $run): JsonResponse
    {
        Gate::authorize('update-datacite-landing-page-urls');

        $query = DataCiteUrlUpdateItem::query()->where('run_id', $run->id)->orderBy('id');
        $status = $request->status();
        if ($status !== null) {
            $query->where('status', $status);
        } elseif ($request->issuesOnly()) {
            $query->where(function ($issues): void {
                $issues->where('status', DataCiteUrlUpdateItemStatus::FAILED)
                    ->orWhere('status', 'like', 'skipped_%');
            });
        }

        $items = $query->paginate(50);

        return response()->json([
            'items' => $items->getCollection()->map(fn (DataCiteUrlUpdateItem $item): array => $this->presenter->item($item))->all(),
            'pagination' => [
                'current_page' => $items->currentPage(),
                'last_page' => $items->lastPage(),
                'total' => $items->total(),
            ],
        ]);
    }

    public function cancel(Request $request, DataCiteUrlUpdateRun $run): JsonResponse
    {
        Gate::authorize('update-datacite-landing-page-urls');
        /** @var User $user */
        $user = $request->user();

        return response()->json(['run' => $this->presenter->run($this->runService->cancel($run, $user))]);
    }

    public function resume(Request $request, DataCiteUrlUpdateRun $run): JsonResponse
    {
        Gate::authorize('update-datacite-landing-page-urls');
        /** @var User $user */
        $user = $request->user();

        return response()->json(['run' => $this->presenter->run($this->runService->resume($run, $user))]);
    }

    public function retryFailed(Request $request, DataCiteUrlUpdateRun $run): JsonResponse
    {
        Gate::authorize('update-datacite-landing-page-urls');
        /** @var User $user */
        $user = $request->user();

        return response()->json(['run' => $this->presenter->run($this->runService->retryFailed($run, $user))]);
    }
}
