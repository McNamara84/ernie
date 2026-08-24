<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\DataCiteUrlUpdateItemStatus;
use App\Models\DataCiteUrlUpdateItem;
use App\Models\DataCiteUrlUpdateRun;
use App\Models\Resource;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<DataCiteUrlUpdateItem> */
class DataCiteUrlUpdateItemFactory extends Factory
{
    protected $model = DataCiteUrlUpdateItem::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        $identifier = '10.5880/test.'.$this->faker->unique()->numerify('#####');

        return [
            'run_id' => DataCiteUrlUpdateRun::factory(),
            'resource_id' => Resource::factory(),
            'identifier' => $identifier,
            'status' => DataCiteUrlUpdateItemStatus::PENDING_PREFLIGHT,
            'before_url' => null,
            'target_url' => "https://dataservices.gfz.de/10.5880/test/example-{$this->faker->unique()->numerify('#####')}",
            'datacite_state' => null,
            'preflight_attempts' => 0,
            'update_attempts' => 0,
            'last_http_status' => null,
            'error_message' => null,
            'processed_at' => null,
        ];
    }
}
