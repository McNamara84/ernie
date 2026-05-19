<?php

declare(strict_types=1);

use App\Models\Subject;
use App\Observers\SubjectObserver;
use App\Services\KeywordSuggestionService;
use Illuminate\Support\Facades\DB;

covers(SubjectObserver::class);

beforeEach(function () {
    $this->keywordService = Mockery::spy(KeywordSuggestionService::class); // @phpstan-ignore variable.undefined
    $this->observer = new SubjectObserver($this->keywordService);
});

describe('saved', function () {
    it('invalidates portal keyword caches after commit', function () {
        $subject = new Subject();

        DB::beginTransaction();

        try {
            $this->observer->saved($subject);

            $this->keywordService->shouldNotHaveReceived('invalidateCache');

            DB::commit();
        } finally {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
        }

        $this->keywordService->shouldHaveReceived('invalidateCache')->once();
    });
});

describe('deleted', function () {
    it('invalidates portal keyword caches after commit', function () {
        $subject = new Subject();

        DB::beginTransaction();

        try {
            $this->observer->deleted($subject);

            $this->keywordService->shouldNotHaveReceived('invalidateCache');

            DB::commit();
        } finally {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
        }

        $this->keywordService->shouldHaveReceived('invalidateCache')->once();
    });
});