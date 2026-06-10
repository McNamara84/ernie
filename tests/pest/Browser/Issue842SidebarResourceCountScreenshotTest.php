<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Models\Resource;
use App\Models\User;
use Tests\TestCase;

uses()->group('issue-842', 'browser', 'resources');

describe('Issue 842 sidebar resource count readability', function (): void {
    it('keeps the active Resources count readable in light mode and captures a screenshot', function (): void {
        /** @var TestCase $this */
        $user = User::factory()->create([
            'role' => UserRole::CURATOR,
        ]);

        Resource::factory()->count(12)->create();

        $this->actingAs($user);

        $page = visit('/resources')
            ->waitForText('Resources')
            ->waitForText('12')
            ->assertNoSmoke();

        $resourceBadgeStyles = $page->script(<<<'JS'
            () => {
                const resourceLink = document.querySelector('[data-sidebar="menu-button"][href="/resources"]');
                const badge = resourceLink
                    ?.closest('[data-sidebar="menu-item"]')
                    ?.querySelector('[data-sidebar="menu-badge"]');

                if (!(badge instanceof HTMLElement)) {
                    return null;
                }

                const style = getComputedStyle(badge);

                const parseRgb = (value) => {
                    const channels = value.match(/\d+(\.\d+)?/g)?.slice(0, 3).map(Number) ?? [];

                    return channels.length === 3 ? channels.map(Math.round) : null;
                };

                const linearize = (channel) => {
                    const normalized = channel / 255;

                    return normalized <= 0.03928
                        ? normalized / 12.92
                        : Math.pow((normalized + 0.055) / 1.055, 2.4);
                };

                const luminance = (rgb) => {
                    const [red, green, blue] = rgb.map(linearize);

                    return (0.2126 * red) + (0.7152 * green) + (0.0722 * blue);
                };

                const textRgb = parseRgb(style.color);
                const backgroundRgb = parseRgb(style.backgroundColor);

                if (textRgb === null || backgroundRgb === null) {
                    return {
                        textRgb,
                        backgroundRgb,
                        rawColor: style.color,
                        rawBackgroundColor: style.backgroundColor,
                        contrastRatio: null,
                    };
                }

                const lighter = Math.max(luminance(textRgb), luminance(backgroundRgb));
                const darker = Math.min(luminance(textRgb), luminance(backgroundRgb));

                return {
                    textRgb,
                    backgroundRgb,
                    rawColor: style.color,
                    rawBackgroundColor: style.backgroundColor,
                    contrastRatio: Number(((lighter + 0.05) / (darker + 0.05)).toFixed(2)),
                };
            }
            JS);

        expect($resourceBadgeStyles)->not->toBeNull();
        expect($resourceBadgeStyles['textRgb'])->toBeArray()->toHaveCount(3);
        expect($resourceBadgeStyles['backgroundRgb'])->toBeArray()->toHaveCount(3);
        expect($resourceBadgeStyles['contrastRatio'])->not->toBeNull();
        expect($resourceBadgeStyles['contrastRatio'])->toBeGreaterThanOrEqual(4.5);

        $page->screenshot(fullPage: true, filename: 'issue-842-resources-sidebar-count-light-mode');
    });
});
