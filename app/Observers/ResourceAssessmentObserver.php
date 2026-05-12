<?php

declare(strict_types=1);

namespace App\Observers;

use App\Models\ResourceAssessment;
use App\Services\Assessment\AssessmentAverageSummaryVersionService;

class ResourceAssessmentObserver
{
    public function saved(ResourceAssessment $resourceAssessment): void
    {
        app(AssessmentAverageSummaryVersionService::class)->bump();
    }

    public function deleted(ResourceAssessment $resourceAssessment): void
    {
        app(AssessmentAverageSummaryVersionService::class)->bump();
    }
}