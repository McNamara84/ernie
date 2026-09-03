<?php

use Illuminate\Support\Facades\File;

use function Pest\Laravel\getJson;

it('returns changelog data grouped by release', function () {
    getJson('/api/changelog')
        ->assertOk()
        ->assertJsonFragment([
            'version' => '1.0.7',
            'date' => '2026-09-04',
        ])
        ->assertJsonFragment([
            'title' => 'SPDX License Gap Filter for Resources',
        ])
        ->assertJsonFragment([
            'title' => 'Frequently Used Licenses First',
        ])
        ->assertJsonFragment([
            'version' => '1.0.1',
            'date' => '2026-08-25',
        ])
        ->assertJsonFragment([
            'title' => 'Preserved License Drafts in the Data Editor',
        ])
        ->assertJsonFragment([
            'title' => 'Short Abstracts Accepted in the Data Editor',
        ])
        ->assertJsonFragment([
            'title' => 'Corrected Review-Link Migration Emails',
        ])
        ->assertJsonFragment([
            'version' => '0.1.0',
        ])
        ->assertJsonFragment([
            'title' => 'Resources workspace',
        ])
        ->assertJsonFragment([
            'title' => 'Dashboard overview',
        ])
        ->assertJsonFragment([
            'title' => 'Assistance: Description Segmentation Suggestions',
        ])
        ->assertJsonFragment([
            'title' => 'Clear Creative Commons License Labels on Landing Pages',
        ])
        ->assertJsonFragment([
            'title' => 'Expanded Repeatable Metadata Editing',
        ]);
});

it('returns an error when changelog JSON is invalid', function () {
    File::shouldReceive('exists')
        ->once()
        ->with(resource_path('data/changelog.json'))
        ->andReturn(true);

    File::shouldReceive('get')
        ->once()
        ->with(resource_path('data/changelog.json'))
        ->andReturn('{invalid');

    getJson('/api/changelog')
        ->assertStatus(500)
        ->assertJson([
            'error' => 'Invalid changelog data',
        ]);
});
