<?php

namespace Database\Factories;

use App\Models\Resource;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\ContactMessage>
 */
class ContactMessageFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'resource_id' => Resource::factory(),
            'resource_creator_id' => null,
            'send_to_all' => fake()->boolean(70),
            'sender_name' => fake()->name(),
            'sender_email' => fake()->safeEmail(),
            'message' => fake()->paragraph(),
            'copy_to_sender' => fake()->boolean(30),
            'ip_address' => fake()->ipv4(),
            'sent_at' => fake()->optional(0.8)->dateTimeBetween('-1 week', 'now'),
        ];
    }

    /**
     * Indicate that the message has not been sent yet.
     */
    public function pending(): static
    {
        return $this->state(fn (array $attributes) => [
            'sent_at' => null,
        ]);
    }

    /**
     * Indicate that the message was sent.
     */
    public function sent(): static
    {
        return $this->state(fn (array $attributes) => [
            'sent_at' => now(),
        ]);
    }
}
