<?php

declare(strict_types=1);

use App\Models\Size;

describe('Size export_string accessor', function () {
    it('formats size with numeric_value, type, and unit', function () {
        $size = new Size([
            'numeric_value' => 3,
            'type' => 'Drilled Length',
            'unit' => 'm',
        ]);

        expect($size->export_string)->toBe('3 Drilled Length [m]');
    });

    it('formats decimal value with type and unit', function () {
        $size = new Size([
            'numeric_value' => 851.88,
            'type' => 'Total Cored Length',
            'unit' => 'm',
        ]);

        expect($size->export_string)->toBe('851.88 Total Cored Length [m]');
    });

    it('formats type with unit in brackets for Core Diameter', function () {
        $size = new Size([
            'numeric_value' => 146,
            'type' => 'Core Diameter',
            'unit' => 'mm',
        ]);

        expect($size->export_string)->toBe('146 Core Diameter [mm]');
    });

    it('formats size with type but no unit', function () {
        $size = new Size([
            'numeric_value' => 5,
            'type' => 'meters',
            'unit' => null,
        ]);

        expect($size->export_string)->toBe('5 meters');
    });

    it('formats size with unit but no type (backward compatible)', function () {
        $size = new Size([
            'numeric_value' => 1.5,
            'type' => null,
            'unit' => 'GB',
        ]);

        expect($size->export_string)->toBe('1.5 GB');
    });

    it('formats size with only numeric_value', function () {
        $size = new Size([
            'numeric_value' => 250,
            'type' => null,
            'unit' => null,
        ]);

        expect($size->export_string)->toBe('250');
    });

    it('strips trailing zeros from numeric_value', function () {
        $size = new Size([
            'numeric_value' => 3,
            'type' => 'Drilled Length',
            'unit' => 'm',
        ]);

        // decimal:4 cast stores as "3.0000", export should strip to "3"
        expect($size->export_string)->toBe('3 Drilled Length [m]');
    });

    it('preserves significant decimal digits', function () {
        $size = new Size([
            'numeric_value' => 0.9,
            'type' => 'Drilled Length',
            'unit' => 'm',
        ]);

        expect($size->export_string)->toBe('0.9 Drilled Length [m]');
    });

    it('formats type and unit without numeric_value', function () {
        $size = new Size([
            'numeric_value' => null,
            'type' => 'Drilled Length',
            'unit' => 'm',
        ]);

        expect($size->export_string)->toBe('Drilled Length [m]');
    });

    it('formats unit only without numeric_value or type', function () {
        $size = new Size([
            'numeric_value' => null,
            'type' => null,
            'unit' => '15 pages',
        ]);

        expect($size->export_string)->toBe('15 pages');
    });

    it('returns empty string when all fields are null', function () {
        $size = new Size([
            'numeric_value' => null,
            'type' => null,
            'unit' => null,
        ]);

        expect($size->export_string)->toBe('');
    });
});
