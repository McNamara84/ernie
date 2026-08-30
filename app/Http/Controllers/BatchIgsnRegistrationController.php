<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\IgsnRegistrationItemStatus;
use App\Http\Requests\Batch\RegisterIgsnsRequest;
use App\Http\Requests\IgsnRegistrationItemsRequest;
use App\Models\IgsnRegistrationItem;
use App\Models\IgsnRegistrationRun;
use App\Models\User;
use App\Services\IgsnRegistrationRunPresenter;
use App\Services\IgsnRegistrationRunService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

final class BatchIgsnRegistrationController extends Controller
{
    public function __construct(
        private readonly IgsnRegistrationRunService $runs,
        private readonly IgsnRegistrationRunPresenter $presenter,
    ) {}

    public function register(RegisterIgsnsRequest $request): JsonResponse
    {
        /** @var array{ids: list<int>} $validated */
        $validated = $request->validated();
        /** @var User $user */
        $user = $request->user();
        $run = $this->runs->start($validated['ids'], $user);

        return response()->json(['run' => $this->presenter->run($run)], 202);
    }

    public function show(IgsnRegistrationRun $registrationRun): JsonResponse
    {
        Gate::authorize('manage-igsn-registration-run', $registrationRun);

        return response()->json([
            'run' => $this->presenter->run($registrationRun->refresh()),
        ]);
    }

    public function items(
        IgsnRegistrationItemsRequest $request,
        IgsnRegistrationRun $registrationRun,
    ): JsonResponse {
        $query = IgsnRegistrationItem::query()
            ->where('run_id', $registrationRun->id)
            ->orderBy('id');

        $status = $request->status();
        if ($status !== null) {
            $query->where('status', $status);
        } elseif ($request->issuesOnly()) {
            $query->whereIn('status', [
                IgsnRegistrationItemStatus::FAILED,
                IgsnRegistrationItemStatus::CANCELLED,
            ]);
        }

        $items = $query->paginate(50);

        return response()->json([
            'items' => $items->getCollection()
                ->map(fn (IgsnRegistrationItem $item): array => $this->presenter->item($item))
                ->all(),
            'pagination' => [
                'current_page' => $items->currentPage(),
                'last_page' => $items->lastPage(),
                'total' => $items->total(),
            ],
        ]);
    }

    public function cancel(Request $request, IgsnRegistrationRun $registrationRun): JsonResponse
    {
        Gate::authorize('manage-igsn-registration-run', $registrationRun);
        /** @var User $user */
        $user = $request->user();

        return response()->json([
            'run' => $this->presenter->run($this->runs->cancel($registrationRun, $user)),
        ]);
    }

    public function resume(Request $request, IgsnRegistrationRun $registrationRun): JsonResponse
    {
        Gate::authorize('manage-igsn-registration-run', $registrationRun);
        /** @var User $user */
        $user = $request->user();

        return response()->json([
            'run' => $this->presenter->run($this->runs->resume($registrationRun, $user)),
        ]);
    }

    public function retryFailed(Request $request, IgsnRegistrationRun $registrationRun): JsonResponse
    {
        Gate::authorize('manage-igsn-registration-run', $registrationRun);
        /** @var User $user */
        $user = $request->user();

        return response()->json([
            'run' => $this->presenter->run($this->runs->retryFailed($registrationRun, $user)),
        ]);
    }
}
