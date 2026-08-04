<?php

declare(strict_types=1);

use App\Enums\UploadErrorCode;
use App\Support\UploadError;

covers(UploadErrorCode::class, UploadError::class);

// =========================================================================
// UploadErrorCode
// =========================================================================

describe('UploadErrorCode', function () {
    it('has 23 error codes', function () {
        expect(UploadErrorCode::cases())->toHaveCount(23);
    });

    it('returns a human-readable message for every code', function () {
        foreach (UploadErrorCode::cases() as $code) {
            expect($code->message())->toBeString()->not->toBeEmpty();
        }
    });

    it('categorizes validation errors correctly', function (UploadErrorCode $code, string $expected) {
        expect($code->category())->toBe($expected);
    })->with([
        [UploadErrorCode::FILE_TOO_LARGE, 'validation'],
        [UploadErrorCode::INVALID_FILE_TYPE, 'validation'],
        [UploadErrorCode::FILE_UNREADABLE, 'validation'],
        [UploadErrorCode::FILE_REQUIRED, 'validation'],
        [UploadErrorCode::MISSING_REQUIRED_FIELD, 'validation'],
    ]);

    it('categorizes data errors correctly', function (UploadErrorCode $code, string $expected) {
        expect($code->category())->toBe($expected);
    })->with([
        [UploadErrorCode::XML_PARSE_ERROR, 'data'],
        [UploadErrorCode::INVALID_XML_STRUCTURE, 'data'],
        [UploadErrorCode::INVALID_DOI_FORMAT, 'data'],
        [UploadErrorCode::CSV_PARSE_ERROR, 'data'],
        [UploadErrorCode::DUPLICATE_DOI, 'data'],
        [UploadErrorCode::DUPLICATE_IGSN, 'data'],
        [UploadErrorCode::NO_VALID_ROWS, 'data'],
    ]);

    it('categorizes server errors correctly', function (UploadErrorCode $code, string $expected) {
        expect($code->category())->toBe($expected);
    })->with([
        [UploadErrorCode::DATABASE_ERROR, 'server'],
        [UploadErrorCode::SESSION_ERROR, 'server'],
        [UploadErrorCode::STORAGE_ERROR, 'server'],
        [UploadErrorCode::UNEXPECTED_ERROR, 'server'],
    ]);

    it('maps categories to correct log levels', function (UploadErrorCode $code, string $expectedLevel) {
        expect($code->logLevel())->toBe($expectedLevel);
    })->with([
        [UploadErrorCode::FILE_TOO_LARGE, 'info'],
        [UploadErrorCode::XML_PARSE_ERROR, 'warning'],
        [UploadErrorCode::DATABASE_ERROR, 'error'],
    ]);
});

// =========================================================================
// UploadError - factory methods
// =========================================================================

describe('UploadError factory methods', function () {
    it('creates from error code with default message', function () {
        $error = UploadError::fromCode(UploadErrorCode::FILE_TOO_LARGE);

        expect($error->code)->toBe(UploadErrorCode::FILE_TOO_LARGE)
            ->and($error->customMessage)->toBeNull()
            ->and($error->message())->toBe('The file exceeds the maximum allowed size.')
            ->and($error->field)->toBeNull()
            ->and($error->row)->toBeNull()
            ->and($error->identifier)->toBeNull();
    });

    it('creates with custom message', function () {
        $error = UploadError::withMessage(UploadErrorCode::FILE_TOO_LARGE, 'Max 10 MB');

        expect($error->message())->toBe('Max 10 MB')
            ->and($error->code)->toBe(UploadErrorCode::FILE_TOO_LARGE);
    });

    it('creates for a specific CSV row', function () {
        $error = UploadError::forRow(UploadErrorCode::DUPLICATE_IGSN, 5, 'IGSN-001', 'Already exists');

        expect($error->row)->toBe(5)
            ->and($error->identifier)->toBe('IGSN-001')
            ->and($error->message())->toBe('Already exists')
            ->and($error->code)->toBe(UploadErrorCode::DUPLICATE_IGSN);
    });

    it('creates for a specific field', function () {
        $error = UploadError::forField(UploadErrorCode::MISSING_REQUIRED_FIELD, 'title', 'Title is required');

        expect($error->field)->toBe('title')
            ->and($error->message())->toBe('Title is required');
    });

    it('creates from legacy error format', function () {
        $legacy = ['row' => 3, 'igsn' => 'IGSN-042', 'message' => 'Bad format'];

        $error = UploadError::fromLegacyError($legacy, UploadErrorCode::CSV_PARSE_ERROR);

        expect($error->row)->toBe(3)
            ->and($error->identifier)->toBe('IGSN-042')
            ->and($error->message())->toBe('Bad format')
            ->and($error->code)->toBe(UploadErrorCode::CSV_PARSE_ERROR);
    });

    it('handles legacy error with missing fields', function () {
        $legacy = [];

        $error = UploadError::fromLegacyError($legacy, UploadErrorCode::CSV_PARSE_ERROR);

        expect($error->row)->toBeNull()
            ->and($error->identifier)->toBeNull()
            ->and($error->customMessage)->toBeNull()
            ->and($error->message())->toBe(UploadErrorCode::CSV_PARSE_ERROR->message());
    });
});

// =========================================================================
// UploadError - toArray()
// =========================================================================

describe('UploadError toArray', function () {
    it('serializes to array with all fields', function () {
        $error = UploadError::forRow(UploadErrorCode::DUPLICATE_IGSN, 7, 'IGSN-099', 'Duplicate');

        $array = $error->toArray();

        expect($array)->toBe([
            'category' => 'data',
            'code' => 'duplicate_igsn',
            'message' => 'Duplicate',
            'field' => null,
            'row' => 7,
            'identifier' => 'IGSN-099',
        ]);
    });

    it('serializes with field info', function () {
        $error = UploadError::forField(UploadErrorCode::MISSING_REQUIRED_FIELD, 'doi');

        $array = $error->toArray();

        expect($array['field'])->toBe('doi')
            ->and($array['row'])->toBeNull()
            ->and($array['identifier'])->toBeNull()
            ->and($array['message'])->toBe(UploadErrorCode::MISSING_REQUIRED_FIELD->message());
    });

    it('returns correct category from code', function () {
        $error = UploadError::fromCode(UploadErrorCode::DATABASE_ERROR);

        expect($error->category())->toBe('server')
            ->and($error->toArray()['category'])->toBe('server');
    });
});
