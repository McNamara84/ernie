<?php

declare(strict_types=1);

use App\Http\Controllers\Settings\FontSizeController;
use App\Models\User;

covers(FontSizeController::class);

describe('PUT /settings/font-size', function (): void {
    test('updates font size preference', function (): void {
        $user = User::factory()->create(['font_size_preference' => 'medium']);

        $this->actingAs($user)
            ->put('/settings/font-size', ['font_size_preference' => 'large'])
            ->assertRedirect();

        expect($user->fresh()->font_size_preference)->toBe('large');
    });

    test('requires authentication', function (): void {
        $this->put('/settings/font-size', ['font_size_preference' => 'large'])
            ->assertRedirect('/login');
    });

    test('rejects invalid font size preference', function (): void {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->put('/settings/font-size', ['font_size_preference' => 'gigantic'])
            ->assertSessionHasErrors('font_size_preference');
    });

    test('rejects missing font size preference', function (): void {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->put('/settings/font-size', [])
            ->assertSessionHasErrors('font_size_preference');
    });
});
