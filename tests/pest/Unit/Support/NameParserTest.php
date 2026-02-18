<?php

declare(strict_types=1);

use App\Support\NameParser;

covers(NameParser::class);

describe('NameParser::parsePersonName()', function () {
    it('uses explicit first and last name when provided', function () {
        $result = NameParser::parsePersonName('Barthelmes, Franz', 'Franz', 'Barthelmes');

        expect($result)
            ->firstName->toBe('Franz')
            ->lastName->toBe('Barthelmes');
    });

    it('uses only lastName when only lastName is provided', function () {
        $result = NameParser::parsePersonName('Some Name', null, 'Barthelmes');

        expect($result)
            ->firstName->toBe('')
            ->lastName->toBe('Barthelmes');
    });

    it('uses only firstName when only firstName is provided', function () {
        $result = NameParser::parsePersonName('Some Name', 'Franz', null);

        expect($result)
            ->firstName->toBe('Franz')
            ->lastName->toBe('');
    });

    it('splits comma-separated name into lastName and firstName', function () {
        $result = NameParser::parsePersonName('Förste, Christoph', null, null);

        expect($result)
            ->firstName->toBe('Christoph')
            ->lastName->toBe('Förste');
    });

    it('handles name with multiple commas by splitting on first', function () {
        $result = NameParser::parsePersonName('Doe, Jane, Sr.', null, null);

        expect($result)
            ->firstName->toBe('Jane, Sr.')
            ->lastName->toBe('Doe');
    });

    it('treats name without comma as lastName only (institution)', function () {
        $result = NameParser::parsePersonName('UNESCO', null, null);

        expect($result)
            ->firstName->toBe('')
            ->lastName->toBe('UNESCO');
    });

    it('returns empty strings when all inputs are null', function () {
        $result = NameParser::parsePersonName(null, null, null);

        expect($result)
            ->firstName->toBe('')
            ->lastName->toBe('');
    });

    it('returns empty strings when name is empty string', function () {
        $result = NameParser::parsePersonName('', null, null);

        expect($result)
            ->firstName->toBe('')
            ->lastName->toBe('');
    });
});

describe('NameParser::isPerson()', function () {
    it('returns true when firstName is present', function () {
        expect(NameParser::isPerson(['firstName' => 'Franz', 'lastName' => 'Barthelmes']))->toBeTrue();
    });

    it('returns false when firstName is empty', function () {
        expect(NameParser::isPerson(['firstName' => '', 'lastName' => 'UNESCO']))->toBeFalse();
    });

    it('identifies comma-separated names as persons', function () {
        $parsed = NameParser::parsePersonName('Förste, Christoph', null, null);
        expect(NameParser::isPerson($parsed))->toBeTrue();
    });

    it('identifies single names as non-persons (institutions)', function () {
        $parsed = NameParser::parsePersonName('UNESCO', null, null);
        expect(NameParser::isPerson($parsed))->toBeFalse();
    });
});
