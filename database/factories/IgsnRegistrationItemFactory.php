<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\IgsnRegistrationItemStatus;
use App\Models\IgsnRegistrationItem;
use App\Models\IgsnRegistrationRun;
use App\Models\Resource;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<IgsnRegistrationItem> */
class IgsnRegistrationItemFactory extends Factory
{
    protected $model = IgsnRegistrationItem::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'run_id' => IgsnRegistrationRun::factory(),
            'resource_id' => Resource::factory(),
            'identifier' => '10.83279/'.strtoupper(fake()->unique()->bothify('TEST-####??')),
            'status' => IgsnRegistrationItemStatus::PENDING,
            'attempts' => 0,
        ];
    }
}
