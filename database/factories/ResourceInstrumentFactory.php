<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Resource;
use App\Models\ResourceInstrument;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ResourceInstrument>
 */
class ResourceInstrumentFactory extends Factory
{
    protected $model = ResourceInstrument::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'resource_id' => Resource::factory(),
            'instrument_pid' => 'http://hdl.handle.net/21.12132/' . $this->faker->unique()->regexify('[A-Z0-9]{8}'),
            'instrument_pid_type' => 'Handle',
            'instrument_name' => $this->faker->sentence(3),
            'position' => 0,
        ];
    }
}
