<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\AssessmentRunItemStatus;
use App\Models\AssessmentRun;
use App\Models\AssessmentRunItem;
use App\Models\Resource;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<AssessmentRunItem> */
class AssessmentRunItemFactory extends Factory
{
    protected $model = AssessmentRunItem::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'run_id' => AssessmentRun::factory(),
            'resource_id' => Resource::factory(),
            'identifier' => '10.5880/'.fake()->unique()->bothify('assessment-####??'),
            'status' => AssessmentRunItemStatus::PENDING,
            'attempts' => 0,
        ];
    }
}
