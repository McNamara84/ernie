<?php

declare(strict_types=1);

use App\Http\Controllers\PublicTrafficController;
use App\Models\User;

covers(PublicTrafficController::class);

it('protects the traffic endpoint and defaults to twelve weeks', function (): void {
    $admin = User::factory()->admin()->create();
    $beginner = User::factory()->beginner()->create();

    $this->get(route('logs.public-traffic'))->assertRedirect(route('login'));
    $this->actingAs($beginner)->getJson(route('logs.public-traffic'))->assertForbidden();
    $this->actingAs($admin)->getJson(route('logs.public-traffic'))
        ->assertOk()
        ->assertJsonPath('period', '12w')
        ->assertJsonCount(168, 'cells');
});

it('accepts supported periods and rejects unsupported values', function (): void {
    $admin = User::factory()->admin()->create();

    foreach (['4w', '12w', '52w'] as $period) {
        $this->actingAs($admin)
            ->getJson(route('logs.public-traffic', ['period' => $period]))
            ->assertOk()
            ->assertJsonPath('period', $period);
    }

    $this->actingAs($admin)
        ->getJson(route('logs.public-traffic', ['period' => 'week']))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('period');
});
