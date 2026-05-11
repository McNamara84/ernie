<?php

declare(strict_types=1);

use App\Services\Assessment\FujiAssessmentService;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

covers(FujiAssessmentService::class);

function makeFujiAssessmentService(): FujiAssessmentService
{
    return new FujiAssessmentService;
}

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
});

describe('isConfigured', function (): void {
    it('returns false when a required config value is missing', function (): void {
        Config::set('fuji.password', null);

        expect(makeFujiAssessmentService()->isConfigured())->toBeFalse();
    });
});

describe('healthStatus', function (): void {
    it('returns healthy when the F-UJI ui endpoint responds successfully', function (): void {
        Http::fake([
            'https://fuji.test/fuji/api/v1/ui/' => Http::response('OK', 200),
        ]);

        expect(makeFujiAssessmentService()->healthStatus())->toBe([
            'healthy' => true,
            'message' => null,
        ]);
    });

    it('returns the configuration error when F-UJI is not configured', function (): void {
        Config::set('fuji.enabled', false);

        expect(makeFujiAssessmentService()->healthStatus())->toBe([
            'healthy' => false,
            'message' => 'F-UJI is not configured.',
        ]);
    });

    it('returns a generic availability message when the health endpoint is unsuccessful and logs the response details', function (): void {
        Log::spy();

        Http::fake([
            'https://fuji.test/fuji/api/v1/ui/' => Http::response('Internal Server Error', 500),
        ]);

        $status = makeFujiAssessmentService()->healthStatus();

        expect($status['healthy'])->toBeFalse()
            ->and($status['message'])->toBe('F-UJI is currently unavailable. Please try again shortly.');

        Log::shouldHaveReceived('warning')
            ->once()
            ->withArgs(fn (string $message, array $context): bool => $message === 'F-UJI returned unsuccessful response'
                && $context['operation'] === 'health check'
                && $context['base_url'] === 'https://fuji.test'
                && $context['status'] === 500
                && $context['body'] === 'Internal Server Error'
            );
    });

    it('returns a generic availability message when the health request cannot connect', function (): void {
        Http::fake([
            'https://fuji.test/fuji/api/v1/ui/' => Http::failedConnection(),
        ]);

        $status = makeFujiAssessmentService()->healthStatus();

        expect($status['healthy'])->toBeFalse()
            ->and($status['message'])->toBe('F-UJI is currently unavailable. Please try again shortly.');
    });
});

describe('assessIdentifier', function (): void {
    it('throws immediately when F-UJI is not configured', function (): void {
        Config::set('fuji.enabled', false);

        expect(fn () => makeFujiAssessmentService()->assessIdentifier('10.5880/test.001'))
            ->toThrow(RuntimeException::class, 'F-UJI is not configured.');
    });

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

        $result = makeFujiAssessmentService()->assessIdentifier('10.5880/test.001');

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

        expect(fn () => makeFujiAssessmentService()->assessIdentifier('10.5880/test.001'))
            ->toThrow(RuntimeException::class, 'summary.score_percent.FAIR');
    });

    it('throws a generic availability message when the F-UJI response is unsuccessful and logs the response details', function (): void {
        Log::spy();

        Http::fake([
            'https://fuji.test/fuji/api/v1/evaluate' => Http::response(['error' => 'Unavailable'], 500),
        ]);

        expect(fn () => makeFujiAssessmentService()->assessIdentifier('10.5880/test.001'))
            ->toThrow(RuntimeException::class, 'F-UJI is currently unavailable. Please try again shortly.');

        Log::shouldHaveReceived('warning')
            ->once()
            ->withArgs(fn (string $message, array $context): bool => $message === 'F-UJI returned unsuccessful response'
                && $context['operation'] === 'assessment'
                && $context['identifier'] === '10.5880/test.001'
                && $context['base_url'] === 'https://fuji.test'
                && $context['status'] === 500
                && is_string($context['body'])
                && str_contains($context['body'], 'Unavailable')
            );
    });

    it('throws a generic availability message when the F-UJI request cannot connect', function (): void {
        Http::fake([
            'https://fuji.test/fuji/api/v1/evaluate' => Http::failedConnection(),
        ]);

        expect(fn () => makeFujiAssessmentService()->assessIdentifier('10.5880/test.001'))
            ->toThrow(RuntimeException::class, 'F-UJI is currently unavailable. Please try again shortly.');
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

        makeFujiAssessmentService()->assessIdentifier('10.5880/test.001');

        Http::assertSent(fn ($request): bool => $request['metric_version'] === 'metrics_v0.8');
    });
});