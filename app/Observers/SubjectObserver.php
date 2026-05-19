<?php

declare(strict_types=1);

namespace App\Observers;

use App\Models\Subject;
use App\Services\KeywordSuggestionService;
use Illuminate\Support\Facades\DB;

class SubjectObserver
{
    public function __construct(
        private readonly KeywordSuggestionService $keywordSuggestionService,
    ) {}

    public function saved(Subject $subject): void
    {
        DB::afterCommit(function (): void {
            $this->keywordSuggestionService->invalidateCache();
        });
    }

    public function deleted(Subject $subject): void
    {
        DB::afterCommit(function (): void {
            $this->keywordSuggestionService->invalidateCache();
        });
    }
}