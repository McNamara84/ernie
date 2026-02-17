<?php

declare(strict_types=1);

/**
 * Pest Browser Tests for Changelog Page
 *
 * Converted from 13 original tests. Tests the public changelog page at /changelog
 * which displays an animated timeline of release notes powered by changelog.json.
 *
 * Note: assertSourceHas() checks for React-rendered DOM are not possible because
 * Pest tests run with withoutVite(), preventing client-side rendering.
 * Only assertNoSmoke() smoke tests are used here.
 *
 * @see https://pestphp.com/docs/browser-testing
 */

describe('Changelog Page (Smoke)', function (): void {

    it('loads the changelog page without JavaScript errors', function (): void {
        visit('/changelog')
            ->assertNoSmoke();
    });
});
