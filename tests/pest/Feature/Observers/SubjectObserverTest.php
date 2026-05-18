<?php

declare(strict_types=1);

use App\Models\Subject;
use App\Observers\SubjectObserver;
use App\Services\KeywordSuggestionService;

covers(SubjectObserver::class);

beforeEach(function () {
    $this->keywordService = Mockery::mock(KeywordSuggestionService::class); // @phpstan-ignore variable.undefined
    $this->observer = new SubjectObserver($this->keywordService);
});

describe('saved', function () {
    it('invalidates portal keyword caches', function () {
        $subject = new Subject();

        $this->keywordService->shouldReceive('invalidateCache')
            ->once();

        $this->observer->saved($subject);
    });
});

describe('deleted', function () {
    it('invalidates portal keyword caches', function () {
        $subject = new Subject();

        $this->keywordService->shouldReceive('invalidateCache')
            ->once();

        $this->observer->deleted($subject);
    });
});