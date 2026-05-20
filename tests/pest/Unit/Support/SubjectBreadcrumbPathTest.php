<?php

declare(strict_types=1);

use App\Support\SubjectBreadcrumbPath;

covers(SubjectBreadcrumbPath::class);

it('normalizes breadcrumb paths into clean segments and hierarchy checks', function (): void {
    $path = "  EARTH SCIENCE>  SOLID EARTH\n>SEISMOLOGY  ";

    expect(SubjectBreadcrumbPath::normalize($path))->toBe('EARTH SCIENCE > SOLID EARTH > SEISMOLOGY')
        ->and(SubjectBreadcrumbPath::segments($path))->toBe([
            'EARTH SCIENCE',
            'SOLID EARTH',
            'SEISMOLOGY',
        ])
        ->and(SubjectBreadcrumbPath::segments(null))->toBe([])
        ->and(SubjectBreadcrumbPath::hasHierarchy($path))->toBeTrue()
        ->and(SubjectBreadcrumbPath::hasHierarchy('SEISMOLOGY'))->toBeFalse();
});

it('prefers an explicit breadcrumb path and only falls back to hierarchical values', function (): void {
    expect(SubjectBreadcrumbPath::preferredPath(
        '  EARTH SCIENCE > SEISMOLOGY  ',
        'Ignored fallback',
    ))->toBe('EARTH SCIENCE > SEISMOLOGY')
        ->and(SubjectBreadcrumbPath::preferredPath(
            null,
            '  EARTH SCIENCE > SOLID EARTH > SEISMOLOGY  ',
        ))->toBe('EARTH SCIENCE > SOLID EARTH > SEISMOLOGY')
        ->and(SubjectBreadcrumbPath::preferredPath(null, 'Seismology'))->toBeNull();
});

it('returns the last segment or a trimmed fallback leaf', function (): void {
    expect(SubjectBreadcrumbPath::leaf('EARTH SCIENCE > SOLID EARTH > SEISMOLOGY'))->toBe('SEISMOLOGY')
        ->and(SubjectBreadcrumbPath::leaf(null, '  Seismology  '))->toBe('Seismology')
        ->and(SubjectBreadcrumbPath::leaf('   ', '   '))->toBeNull();
});