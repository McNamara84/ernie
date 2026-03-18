<?php

declare(strict_types=1);

use App\Exceptions\ResourceAlreadyExistsException;
use App\Exceptions\VocabularyCorruptedException;
use App\Exceptions\VocabularyNotFoundException;
use App\Exceptions\VocabularyReadException;

/*
|--------------------------------------------------------------------------
| ResourceAlreadyExistsException
|--------------------------------------------------------------------------
*/

describe('ResourceAlreadyExistsException', function () {
    it('creates exception with default message', function () {
        $exception = new ResourceAlreadyExistsException('landing page', 42);

        expect($exception->getMessage())->toBe('Landing page already exists for identifier: 42');
        expect($exception->resourceType)->toBe('landing page');
        expect($exception->identifier)->toBe(42);
    });

    it('creates exception with string identifier', function () {
        $exception = new ResourceAlreadyExistsException('resource', 'doi-123');

        expect($exception->getMessage())->toBe('Resource already exists for identifier: doi-123');
        expect($exception->identifier)->toBe('doi-123');
    });

    it('creates exception with custom message', function () {
        $exception = new ResourceAlreadyExistsException('page', 1, 'Custom error');

        expect($exception->getMessage())->toBe('Custom error');
        expect($exception->resourceType)->toBe('page');
        expect($exception->identifier)->toBe(1);
    });

    it('extends Exception class', function () {
        $exception = new ResourceAlreadyExistsException('test', 1);

        expect($exception)->toBeInstanceOf(Exception::class);
    });
});

/*
|--------------------------------------------------------------------------
| VocabularyCorruptedException
|--------------------------------------------------------------------------
*/

describe('VocabularyCorruptedException', function () {
    it('creates exception with JSON error message', function () {
        $exception = new VocabularyCorruptedException('Syntax error');

        expect($exception->getMessage())->toBe('Invalid JSON in vocabulary file: Syntax error');
    });

    it('extends RuntimeException', function () {
        $exception = new VocabularyCorruptedException('test');

        expect($exception)->toBeInstanceOf(RuntimeException::class);
    });
});

/*
|--------------------------------------------------------------------------
| VocabularyNotFoundException
|--------------------------------------------------------------------------
*/

describe('VocabularyNotFoundException', function () {
    it('creates exception with command name', function () {
        $exception = new VocabularyNotFoundException('php artisan gcmd:fetch');

        expect($exception->getMessage())->toBe('Vocabulary file not found. Please run: php artisan gcmd:fetch');
    });

    it('extends RuntimeException', function () {
        $exception = new VocabularyNotFoundException('test');

        expect($exception)->toBeInstanceOf(RuntimeException::class);
    });
});

/*
|--------------------------------------------------------------------------
| VocabularyReadException
|--------------------------------------------------------------------------
*/

describe('VocabularyReadException', function () {
    it('creates exception with fixed message', function () {
        $exception = new VocabularyReadException;

        expect($exception->getMessage())->toBe('Failed to read vocabulary file.');
    });

    it('extends RuntimeException', function () {
        $exception = new VocabularyReadException;

        expect($exception)->toBeInstanceOf(RuntimeException::class);
    });
});
