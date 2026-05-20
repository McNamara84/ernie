<?php

declare(strict_types=1);

use App\Models\Subject;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

function loadSubjectBreadcrumbPathMigration(): Migration
{
    return require database_path('migrations/2026_05_20_000001_add_breadcrumb_path_to_subjects.php');
}

function runSubjectBreadcrumbPathMigration(): void
{
    $migration = loadSubjectBreadcrumbPathMigration();
    $migration->up();
}

it('adds the breadcrumb_path column when missing and removes it on rollback', function (): void {
    $migration = loadSubjectBreadcrumbPathMigration();

    expect(Schema::hasColumn('subjects', 'breadcrumb_path'))->toBeTrue();

    $migration->down();

    expect(Schema::hasColumn('subjects', 'breadcrumb_path'))->toBeFalse();

    $migration = loadSubjectBreadcrumbPathMigration();
    $migration->up();

    expect(Schema::hasColumn('subjects', 'breadcrumb_path'))->toBeTrue();
});

it('backfills breadcrumb_path from stable vocabulary identifiers', function (): void {
    Storage::fake('local');
    Storage::disk('local')->put('gcmd-science-keywords.json', json_encode([
        'data' => [[
            'id' => 'science-root',
            'text' => 'Science Keywords',
            'scheme' => 'NASA/GCMD Earth Science Keywords',
            'children' => [[
                'id' => 'earth-science',
                'text' => 'EARTH SCIENCE',
                'scheme' => 'NASA/GCMD Earth Science Keywords',
                'children' => [[
                    'id' => 'solid-earth',
                    'text' => 'SOLID EARTH',
                    'scheme' => 'NASA/GCMD Earth Science Keywords',
                    'children' => [[
                        'id' => 'science-seismology',
                        'text' => 'SEISMOLOGY',
                        'scheme' => 'NASA/GCMD Earth Science Keywords',
                        'children' => [],
                    ]],
                ]],
            ]],
        ]],
    ], JSON_THROW_ON_ERROR));

    $subject = Subject::factory()->create([
        'value' => 'SEISMOLOGY',
        'subject_scheme' => 'NASA/GCMD Earth Science Keywords',
        'value_uri' => 'science-seismology',
        'breadcrumb_path' => null,
    ]);

    runSubjectBreadcrumbPathMigration();

    expect($subject->fresh()->breadcrumb_path)->toBe('EARTH SCIENCE > SOLID EARTH > SEISMOLOGY');
});

it('backfills breadcrumb_path from legacy embedded hierarchical values', function (): void {
    $subject = Subject::factory()->create([
        'value' => 'EARTH SCIENCE > SOLID EARTH > SEISMOLOGY',
        'subject_scheme' => 'GCMD Science Keywords',
        'value_uri' => null,
        'breadcrumb_path' => null,
    ]);

    runSubjectBreadcrumbPathMigration();

    expect($subject->fresh()->breadcrumb_path)->toBe('EARTH SCIENCE > SOLID EARTH > SEISMOLOGY');
});

it('leaves unresolved controlled subjects without breadcrumb_path', function (): void {
    Storage::fake('local');

    $subject = Subject::factory()->create([
        'value' => 'Unmapped Leaf',
        'subject_scheme' => 'Science Keywords',
        'value_uri' => 'missing-node',
        'breadcrumb_path' => null,
    ]);

    runSubjectBreadcrumbPathMigration();

    expect($subject->fresh()->breadcrumb_path)->toBeNull();
});