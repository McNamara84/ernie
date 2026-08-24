<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\DataCiteUrlUpdateRunStatus;
use App\Enums\DataCiteUrlUpdateScope;
use App\Models\DataCiteUrlUpdateRun;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<DataCiteUrlUpdateRun> */
class DataCiteUrlUpdateRunFactory extends Factory
{
    protected $model = DataCiteUrlUpdateRun::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'scope' => DataCiteUrlUpdateScope::RESOURCES,
            'status' => DataCiteUrlUpdateRunStatus::COMPLETED,
            'active_marker' => null,
            'initiated_by_user_id' => User::factory(),
            'last_controlled_by_user_id' => null,
            'test_mode' => true,
            'datacite_endpoint' => 'https://api.test.datacite.org',
            'target_base_url' => 'https://dataservices.gfz.de',
            'total' => 0,
            'processed' => 0,
            'updated' => 0,
            'already_current' => 0,
            'skipped' => 0,
            'failed' => 0,
            'completed_at' => now(),
        ];
    }

    public function active(): static
    {
        return $this->state(fn (): array => [
            'status' => DataCiteUrlUpdateRunStatus::QUEUED,
            'active_marker' => DataCiteUrlUpdateRun::ACTIVE_MARKER,
            'completed_at' => null,
        ]);
    }
}
