<?php

declare(strict_types=1);

use App\Enums\UploadErrorCode;
use App\Models\User;
use App\Services\UploadLogService;
use App\Support\UploadError;
use Illuminate\Support\Facades\Log;

beforeEach(function () {
    $this->service = new UploadLogService;
    $this->actingAs(User::factory()->create());
});

describe('logFailure', function () {
    test('logs upload failure with structured data', function () {
        $error = UploadError::fromCode(UploadErrorCode::XML_PARSE_ERROR);

        Log::shouldReceive('error')
            ->once()
            ->withArgs(function (string $message, array $context) {
                return str_contains($message, 'xml')
                    && $context['upload_type'] === 'xml'
                    && $context['filename'] === 'test.xml'
                    && $context['error_code'] === 'xml_parse_error'
                    && $context['error_category'] === 'data'
                    && isset($context['user_id'])
                    && isset($context['timestamp']);
            });

        $this->service->logFailure('xml', 'test.xml', $error);
    });

    test('includes custom context in log entry', function () {
        $error = UploadError::fromCode(UploadErrorCode::FILE_TOO_LARGE);

        Log::shouldReceive('warning')
            ->once()
            ->withArgs(function (string $message, array $context) {
                return $context['max_size'] === '10MB';
            });

        $this->service->logFailure('csv', 'big.csv', $error, ['max_size' => '10MB']);
    });

    test('includes row-level error details', function () {
        $error = UploadError::forRow(UploadErrorCode::DUPLICATE_IGSN, 5, 'IGSN-001', 'Already exists');

        Log::shouldReceive('error')
            ->once()
            ->withArgs(function (string $message, array $context) {
                return $context['error_row'] === 5
                    && $context['error_identifier'] === 'IGSN-001'
                    && $context['error_message'] === 'Already exists';
            });

        $this->service->logFailure('csv', 'test.csv', $error);
    });
});

describe('logSimpleFailure', function () {
    test('creates error from code and logs it', function () {
        Log::shouldReceive('warning')
            ->once()
            ->withArgs(function (string $message, array $context) {
                return $context['error_code'] === 'file_too_large';
            });

        $this->service->logSimpleFailure('xml', 'test.xml', UploadErrorCode::FILE_TOO_LARGE);
    });

    test('allows custom message override', function () {
        Log::shouldReceive('warning')
            ->once()
            ->withArgs(function (string $message, array $context) {
                return $context['error_message'] === 'File exceeds 50MB limit';
            });

        $this->service->logSimpleFailure(
            'xml',
            'test.xml',
            UploadErrorCode::FILE_TOO_LARGE,
            'File exceeds 50MB limit'
        );
    });
});

describe('logMultipleErrors', function () {
    test('logs multiple errors with count', function () {
        $errors = [
            UploadError::forRow(UploadErrorCode::DUPLICATE_IGSN, 1, 'IGSN-001'),
            UploadError::forRow(UploadErrorCode::MISSING_REQUIRED_FIELD, 2, 'IGSN-002'),
        ];

        Log::shouldReceive('warning')
            ->once()
            ->withArgs(function (string $message, array $context) {
                return str_contains($message, 'IGSN CSV')
                    && $context['total_errors'] === 2
                    && $context['logged_errors'] === 2
                    && count($context['errors']) === 2;
            });

        $this->service->logMultipleErrors('test.csv', $errors);
    });

    test('caps logged errors at 20', function () {
        $errors = array_map(
            fn (int $i) => UploadError::forRow(UploadErrorCode::DUPLICATE_IGSN, $i, "IGSN-{$i}"),
            range(1, 30)
        );

        Log::shouldReceive('warning')
            ->once()
            ->withArgs(function (string $message, array $context) {
                return $context['total_errors'] === 30
                    && $context['logged_errors'] === 20
                    && count($context['errors']) === 20;
            });

        $this->service->logMultipleErrors('test.csv', $errors);
    });
});

describe('logLegacyErrors', function () {
    test('converts legacy error format and logs', function () {
        $legacyErrors = [
            ['row' => 1, 'igsn' => 'IGSN-001', 'message' => 'Some error'],
            ['row' => 3, 'message' => 'Another error'],
        ];

        Log::shouldReceive('warning')
            ->once()
            ->withArgs(function (string $message, array $context) {
                return $context['total_errors'] === 2;
            });

        $this->service->logLegacyErrors('test.csv', $legacyErrors);
    });
});

describe('logSuccess', function () {
    test('logs successful upload', function () {
        Log::shouldReceive('info')
            ->once()
            ->withArgs(function (string $message, array $context) {
                return str_contains($message, 'csv')
                    && $context['upload_type'] === 'csv'
                    && $context['filename'] === 'samples.csv'
                    && isset($context['user_id'])
                    && isset($context['timestamp']);
            });

        $this->service->logSuccess('csv', 'samples.csv');
    });

    test('includes additional context', function () {
        Log::shouldReceive('info')
            ->once()
            ->withArgs(function (string $message, array $context) {
                return $context['row_count'] === 42;
            });

        $this->service->logSuccess('csv', 'samples.csv', ['row_count' => 42]);
    });
});
