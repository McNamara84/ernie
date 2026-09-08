<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\AssessmentRunStatus;
use App\Enums\AssessmentScope;
use App\Models\AssessmentRun;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<AssessmentRun> */
class AssessmentRunFactory extends Factory
{
    protected $model = AssessmentRun::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'scope' => AssessmentScope::RESOURCE,
            'status' => AssessmentRunStatus::QUEUED,
            'active_scope' => AssessmentScope::RESOURCE,
            'initiated_by_user_id' => User::factory(),
            'fuji_base_url' => 'https://fuji.test',
            'metric_version' => 'metrics_v0.8',
            'use_datacite' => true,
            'use_github' => false,
            'concurrency' => 2,
            'requests_per_minute' => 80,
            'total' => 1,
            'pending' => 1,
        ];
    }
}
