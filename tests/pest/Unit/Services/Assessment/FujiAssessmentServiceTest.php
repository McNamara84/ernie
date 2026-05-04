<?php

declare(strict_types=1);

use App\Services\Assessment\FujiAssessmentService;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;

covers(FujiAssessmentService::class);

beforeEach(function (): void {
    Config::set('fuji.enabled', true);
    Config::set('fuji.base_url', 'https://fuji.test');
    Config::set('fuji.username', 'admin');
    Config::set('fuji.password', 'secret');
    Config::set('fuji.timeout', 30);
    Config::set('fuji.connect_timeout', 5);
    Config::set('fuji.use_datacite', true);
    Config::set('fuji.use_github', false);
    Config::set('fuji.test_debug', false);
    Config::set('fuji.metric_version', null);

    $this->service = new FujiAssessmentService;
});

describe('isConfigured', function (): void {
    it('returns false when a required config value is missing', function (): void {
        Config::set('fuji.password', null);

        expect($this->service->isConfigured())->toBeFalse();
    });
});

describe('assessIdentifier', function (): void {
    it('posts the identifier to the F-UJI evaluate endpoint and returns the FAIR percentage score', function (): void {
        Http::fake([
            'https://fuji.test/fuji/api/v1/evaluate' => Http::response([
                'summary' => [
                    'score_percent' => [
                        'FAIR' => 72.5,
                    ],
                ],
                'resolved_url' => 'https://ernie.example.test/10.5880/test.001/example-dataset',
                'request' => [
                    'normalized_object_identifier' => '10.5880/test.001',
                ],
            ]),
        ]);

        $result = $this->service->assessIdentifier('10.5880/test.001');

        expect($result['score'])->toBe(72.5)
            ->and($result['resolvedUrl'])->toBe('https://ernie.example.test/10.5880/test.001/example-dataset')
            ->and($result['normalizedIdentifier'])->toBe('10.5880/test.001');

        Http::assertSent(function ($request): bool {
            return $request->url() === 'https://fuji.test/fuji/api/v1/evaluate'
                && $request['object_identifier'] === '10.5880/test.001'
                && $request['use_datacite'] === true
                && $request['use_github'] === false
                && $request->hasHeader('Authorization', ['Basic ' . base64_encode('admin:secret')]);
        });
    });

    it('throws when the FAIR score is missing from the response', function (): void {
        Http::fake([
            'https://fuji.test/fuji/api/v1/evaluate' => Http::response([
                'summary' => [],
            ]),
        ]);

        expect(fn () => $this->service->assessIdentifier('10.5880/test.001'))
            ->toThrow(RuntimeException::class, 'summary.score_percent.FAIR');
    });

    it('throws when the F-UJI response is unsuccessful', function (): void {
        Http::fake([
            'https://fuji.test/fuji/api/v1/evaluate' => Http::response(['error' => 'Unavailable'], 500),
        ]);

        expect(fn () => $this->service->assessIdentifier('10.5880/test.001'))
            ->toThrow(RuntimeException::class, 'status 500');
    });

    it('includes the configured metric version in the request payload', function (): void {
        Config::set('fuji.metric_version', 'metrics_v0.8');

        Http::fake([
            'https://fuji.test/fuji/api/v1/evaluate' => Http::response([
                'summary' => [
                    'score_percent' => [
                        'FAIR' => 55.5,
                    ],
                ],
            ]),
        ]);

        $this->service->assessIdentifier('10.5880/test.001');

        Http::assertSent(fn ($request): bool => $request['metric_version'] === 'metrics_v0.8');
    });
});