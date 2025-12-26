<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Resource;
use App\Models\Subject;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Subject>
 */
class SubjectFactory extends Factory
{
    protected $model = Subject::class;

    /**
     * Define the model's default state (free-text keyword).
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'resource_id' => Resource::factory(),
            'value' => $this->faker->word(),
            'language' => 'en',
            'subject_scheme' => null,
            'scheme_uri' => null,
            'value_uri' => null,
            'classification_code' => null,
        ];
    }

    /**
     * Create a GCMD Science Keyword.
     */
    public function gcmd(): static
    {
        return $this->state(fn (array $attributes) => [
            'value' => 'EARTH SCIENCE > ATMOSPHERE > ATMOSPHERIC CHEMISTRY',
            'subject_scheme' => 'GCMD Science Keywords',
            'scheme_uri' => 'https://gcmd.earthdata.nasa.gov/kms/concepts/concept_scheme/sciencekeywords',
            'value_uri' => 'https://gcmd.earthdata.nasa.gov/kms/concept/'.$this->faker->uuid(),
        ]);
    }

    /**
     * Create an MSL keyword.
     */
    public function msl(): static
    {
        return $this->state(fn (array $attributes) => [
            'value' => 'Geochemistry',
            'subject_scheme' => 'MSL Vocabularies',
            'scheme_uri' => 'https://epos-msl.uu.nl/voc/',
            'value_uri' => 'https://epos-msl.uu.nl/voc/'.$this->faker->uuid(),
        ]);
    }
}
