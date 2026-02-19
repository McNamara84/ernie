<?php

declare(strict_types=1);

use App\Enums\UploadErrorCode;
use App\Services\UploadLogService;
use App\Support\UploadError;
use Illuminate\Support\Facades\Log;

covers(UploadLogService::class);

beforeEach(function () {
    $this->service = new UploadLogService;
});

// =========================================================================
// logFailure()
// =========================================================================

describe('logFailure', function () {
    it('logs validation errors at info level', function () {
        Log::spy();

        $error = UploadError::fromCode(UploadErrorCode::FILE_TOO_LARGE);
        $this->service->logFailure('xml', 'test.xml', $error);

        Log::shouldHaveReceived('info')
            ->once()
            ->withArgs(fn (string $msg, array $ctx) => str_contains($msg, 'xml')
                && $ctx['error_code'] === 'file_too_large'
                && $ctx['error_category'] === 'validation'
                && $ctx['filename'] === 'test.xml'
            );
    });

    it('logs data errors at warning level', function () {
        Log::spy();

        $error = UploadError::fromCode(UploadErrorCode::XML_PARSE_ERROR);
        $this->service->logFailure('xml', 'broken.xml', $error);

        Log::shouldHaveReceived('warning')->once();
    });

    it('logs server errors at error level', function () {
        Log::spy();

        $error = UploadError::fromCode(UploadErrorCode::DATABASE_ERROR);
        $this->service->logFailure('csv', 'data.csv', $error);

        Log::shouldHaveReceived('error')->once();
    });

    it('includes additional context', function () {
        Log::spy();

        $error = UploadError::fromCode(UploadErrorCode::DUPLICATE_DOI);
        $this->service->logFailure('xml', 'dup.xml', $error, ['doi' => '10.5880/test']);

        Log::shouldHaveReceived('warning')
            ->once()
            ->withArgs(fn (string $msg, array $ctx) => $ctx['doi'] === '10.5880/test');
    });
});

// =========================================================================
// logSimpleFailure()
// =========================================================================

describe('logSimpleFailure', function () {
    it('logs with default error code message', function () {
        Log::spy();

        $this->service->logSimpleFailure('xml', 'test.xml', UploadErrorCode::INVALID_FILE_TYPE);

        Log::shouldHaveReceived('info')
            ->once()
            ->withArgs(fn (string $msg, array $ctx) => $ctx['error_code'] === 'invalid_file_type'
                && $ctx['error_message'] === 'The file type is not supported.'
            );
    });

    it('logs with custom message when provided', function () {
        Log::spy();

        $this->service->logSimpleFailure('csv', 'data.csv', UploadErrorCode::CSV_PARSE_ERROR, 'Row 5 invalid');

        Log::shouldHaveReceived('warning')
            ->once()
            ->withArgs(fn (string $msg, array $ctx) => $ctx['error_message'] === 'Row 5 invalid');
    });
});

// =========================================================================
// logMultipleErrors()
// =========================================================================

describe('logMultipleErrors', function () {
    it('logs multiple row errors as warning', function () {
        Log::spy();

        $errors = [
            UploadError::forRow(UploadErrorCode::DUPLICATE_IGSN, 1, 'IGSN-001'),
            UploadError::forRow(UploadErrorCode::MISSING_REQUIRED_FIELD, 2, 'IGSN-002'),
        ];

        $this->service->logMultipleErrors('samples.csv', $errors);

        Log::shouldHaveReceived('warning')
            ->once()
            ->withArgs(fn (string $msg, array $ctx) => $ctx['total_errors'] === 2
                && $ctx['logged_errors'] === 2
                && count($ctx['errors']) === 2
            );
    });

    it('limits logged errors to 20 to prevent bloat', function () {
        Log::spy();

        $errors = array_map(
            fn (int $i) => UploadError::forRow(UploadErrorCode::MISSING_REQUIRED_FIELD, $i, "IGSN-{$i}"),
            range(1, 30)
        );

        $this->service->logMultipleErrors('large.csv', $errors);

        Log::shouldHaveReceived('warning')
            ->once()
            ->withArgs(fn (string $msg, array $ctx) => $ctx['total_errors'] === 30
                && $ctx['logged_errors'] === 20
                && count($ctx['errors']) === 20
            );
    });
});

// =========================================================================
// logLegacyErrors()
// =========================================================================

describe('logLegacyErrors', function () {
    it('converts legacy format errors and logs them', function () {
        Log::spy();

        $legacyErrors = [
            ['row' => 1, 'igsn' => 'IGSN-A', 'message' => 'Missing title'],
            ['row' => 3, 'igsn' => 'IGSN-B', 'message' => 'Invalid format'],
        ];

        $this->service->logLegacyErrors('old.csv', $legacyErrors);

        Log::shouldHaveReceived('warning')
            ->once()
            ->withArgs(fn (string $msg, array $ctx) => $ctx['total_errors'] === 2
                && $ctx['errors'][0]['row'] === 1
                && $ctx['errors'][0]['identifier'] === 'IGSN-A'
                && $ctx['errors'][0]['message'] === 'Missing title'
            );
    });
});

// =========================================================================
// logSuccess()
// =========================================================================

describe('logSuccess', function () {
    it('logs successful upload at info level', function () {
        Log::spy();

        $this->service->logSuccess('xml', 'metadata.xml', ['resource_id' => 42]);

        Log::shouldHaveReceived('info')
            ->once()
            ->withArgs(fn (string $msg, array $ctx) => str_contains($msg, 'xml')
                && $ctx['filename'] === 'metadata.xml'
                && $ctx['resource_id'] === 42
            );
    });
});
