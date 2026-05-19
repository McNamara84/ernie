<?php

declare(strict_types=1);

use App\Services\KeywordSuggestionService;
use App\Services\PortalKeywordCacheInvalidationService;
use Illuminate\Support\Facades\DB;

covers(PortalKeywordCacheInvalidationService::class);

beforeEach(function () {
    $this->keywordService = Mockery::spy(KeywordSuggestionService::class); // @phpstan-ignore variable.undefined
    app()->instance(KeywordSuggestionService::class, $this->keywordService);
    app()->forgetInstance(PortalKeywordCacheInvalidationService::class);

    $this->service = app(PortalKeywordCacheInvalidationService::class);
});

it('coalesces repeated invalidations until the surrounding transaction commits', function () {
    DB::beginTransaction();

    try {
        $this->service->scheduleAfterCommit();
        $this->service->scheduleAfterCommit();

        $this->keywordService->shouldNotHaveReceived('invalidateCache');

        DB::commit();
    } finally {
        if (DB::transactionLevel() > 0) {
            DB::rollBack();
        }
    }

    $this->keywordService->shouldHaveReceived('invalidateCache')->once();
});

it('resets the scheduled flag when the transaction rolls back', function () {
    DB::beginTransaction();

    try {
        $this->service->scheduleAfterCommit();

        DB::rollBack();
    } finally {
        if (DB::transactionLevel() > 0) {
            DB::rollBack();
        }
    }

    $this->keywordService->shouldNotHaveReceived('invalidateCache');

    $this->service->scheduleAfterCommit();

    $this->keywordService->shouldHaveReceived('invalidateCache')->once();
});