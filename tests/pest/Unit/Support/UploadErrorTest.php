<?php

declare(strict_types=1);

use App\Enums\UploadErrorCode;
use App\Support\UploadError;

covers(UploadError::class);

test('fromCode creates error with default message', function (): void {
    $error = UploadError::fromCode(UploadErrorCode::FILE_TOO_LARGE);

    expect($error->code)->toBe(UploadErrorCode::FILE_TOO_LARGE)
        ->and($error->customMessage)->toBeNull()
        ->and($error->message())->toBe('The file exceeds the maximum allowed size.');
});

test('withMessage creates error with custom message', function (): void {
    $error = UploadError::withMessage(UploadErrorCode::XML_PARSE_ERROR, 'Custom parse error');

    expect($error->code)->toBe(UploadErrorCode::XML_PARSE_ERROR)
        ->and($error->customMessage)->toBe('Custom parse error')
        ->and($error->message())->toBe('Custom parse error');
});

test('forRow creates error with row and identifier', function (): void {
    $error = UploadError::forRow(UploadErrorCode::DUPLICATE_IGSN, 5, 'IGSN123', 'Row error');

    expect($error->row)->toBe(5)
        ->and($error->identifier)->toBe('IGSN123')
        ->and($error->message())->toBe('Row error');
});

test('forField creates error with field name', function (): void {
    $error = UploadError::forField(UploadErrorCode::MISSING_REQUIRED_FIELD, 'title');

    expect($error->field)->toBe('title')
        ->and($error->code)->toBe(UploadErrorCode::MISSING_REQUIRED_FIELD);
});

test('category delegates to error code', function (): void {
    $error = UploadError::fromCode(UploadErrorCode::DATABASE_ERROR);
    expect($error->category())->toBe('server');

    $error = UploadError::fromCode(UploadErrorCode::FILE_TOO_LARGE);
    expect($error->category())->toBe('validation');
});

test('toArray returns correct structure', function (): void {
    $error = UploadError::forRow(UploadErrorCode::CSV_PARSE_ERROR, 3, 'ROW3', 'Bad CSV');

    $array = $error->toArray();

    expect($array)->toHaveKeys(['category', 'code', 'message', 'field', 'row', 'identifier'])
        ->and($array['category'])->toBe('data')
        ->and($array['code'])->toBe('csv_parse_error')
        ->and($array['message'])->toBe('Bad CSV')
        ->and($array['row'])->toBe(3)
        ->and($array['identifier'])->toBe('ROW3')
        ->and($array['field'])->toBeNull();
});

test('fromLegacyError converts legacy format', function (): void {
    $legacy = ['row' => 7, 'igsn' => 'IGSN789', 'message' => 'Legacy error'];
    $error = UploadError::fromLegacyError($legacy, UploadErrorCode::DUPLICATE_IGSN);

    expect($error->row)->toBe(7)
        ->and($error->identifier)->toBe('IGSN789')
        ->and($error->message())->toBe('Legacy error')
        ->and($error->code)->toBe(UploadErrorCode::DUPLICATE_IGSN);
});

test('fromLegacyError handles minimal legacy data', function (): void {
    $error = UploadError::fromLegacyError([], UploadErrorCode::UNEXPECTED_ERROR);

    expect($error->row)->toBeNull()
        ->and($error->identifier)->toBeNull()
        ->and($error->customMessage)->toBeNull()
        ->and($error->message())->toBe('An unexpected error occurred. Please try again.');
});
