<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\NewsKind;
use App\Models\News;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<News> */
final class NewsFactory extends Factory
{
    protected $model = News::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return ['title' => fake()->sentence(), 'body_markdown' => fake()->paragraph(), 'kind' => NewsKind::REGULAR, 'revision' => 1];
    }

    public function temporary(): static
    {
        return $this->state(fn (): array => [
            'kind' => NewsKind::TEMPORARY, 'starts_at' => now()->addDay(), 'ends_at' => now()->addDays(2),
        ]);
    }

    public function scheduled(): static
    {
        return $this->temporary()->state(['is_scheduled' => true]);
    }

    public function archived(): static
    {
        return $this->state(['archived_at' => now(), 'is_scheduled' => false]);
    }
}
