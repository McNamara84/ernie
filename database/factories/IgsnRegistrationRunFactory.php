<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\IgsnRegistrationRunStatus;
use App\Models\IgsnRegistrationRun;
use App\Models\User;
use App\Services\DataCiteMemberApiClient;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<IgsnRegistrationRun> */
class IgsnRegistrationRunFactory extends Factory
{
    protected $model = IgsnRegistrationRun::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'initiated_by_user_id' => User::factory(),
            'status' => IgsnRegistrationRunStatus::QUEUED,
            'test_mode' => true,
            'datacite_context_fingerprint' => app(DataCiteMemberApiClient::class)->contextFingerprint(),
            'datacite_endpoint' => 'https://api.test.datacite.org',
            'total' => 1,
        ];
    }
}
