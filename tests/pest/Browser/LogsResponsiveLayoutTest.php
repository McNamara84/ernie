<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Models\SystemMetricSample;
use App\Models\User;
use Illuminate\Foundation\Vite;
use Tests\TestCase;

uses()->group('browser', 'logs', 'responsive');

describe('Logs responsive layout', function (): void {
    beforeEach(function (): void {
        app(Vite::class)
            ->useHotFile(storage_path('framework/testing-vite.hot'))
            ->useBuildDirectory('build');

        config()->set('system_metrics.enabled', true);
    });

    it('keeps VM metrics and log controls inside a smartphone viewport', function (): void {
        /** @var TestCase $this */
        $admin = User::factory()->create([
            'role' => UserRole::ADMIN,
        ]);

        SystemMetricSample::query()->create([
            'recorded_at' => now('UTC')->startOfMinute(),
            'cpu_usage_percent' => 42.5,
            'memory_usage_percent' => 60,
            'memory_used_bytes' => 10_307_921_510,
            'memory_total_bytes' => 17_179_869_184,
            'cpu_total_ticks' => 1_000_000,
            'cpu_idle_ticks' => 575_000,
        ]);

        $this->actingAs($admin);

        $page = visit('/logs?search=__responsive_layout_no_log_match__')
            ->resize(393, 852)
            ->waitForText('Server utilization')
            ->waitForText('42.5%')
            ->waitForText('60.0%')
            ->waitForText('0 log entries found')
            ->wait(0.5)
            ->assertNoSmoke();

        $layout = $page->script(<<<'JS'
            () => {
                const viewportWidth = window.innerWidth;
                const metricsSection = document.querySelector('[aria-labelledby="server-utilization-title"]');
                const metricCards = Array.from(metricsSection?.querySelectorAll('[data-slot="card"]') ?? []);
                const currentReadings = [
                    document.querySelector('[aria-label="CPU utilization: 42.5 percent"]'),
                    document.querySelector('[aria-label="Memory utilization: 60.0 percent"]'),
                ];
                const logsTitle = Array.from(document.querySelectorAll('[data-slot="card-title"]'))
                    .find((element) => element.textContent?.trim() === 'Application Logs');
                const logsCard = logsTitle?.closest('[data-slot="card"]') ?? null;
                const logButtons = Array.from(logsCard?.querySelectorAll('button') ?? []);
                const elements = [metricsSection, ...metricCards, ...currentReadings, logsCard, ...logButtons]
                    .filter((element) => element instanceof HTMLElement || element instanceof SVGElement);

                return {
                    viewportWidth,
                    documentWidth: document.documentElement.scrollWidth,
                    currentReadingsPresent: currentReadings.every((element) => element instanceof HTMLElement),
                    elementsStayInsideViewport: elements.every((element) => {
                        const bounds = element.getBoundingClientRect();

                        return bounds.left >= -0.5 && bounds.right <= viewportWidth + 0.5;
                    }),
                };
            }
            JS);

        expect($layout['documentWidth'])->toBeLessThanOrEqual($layout['viewportWidth']);
        expect($layout['currentReadingsPresent'])->toBeTrue();
        expect($layout['elementsStayInsideViewport'])->toBeTrue();
    });
});
