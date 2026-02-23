<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Inertia\Testing\AssertableInertia as Assert;

use function Pest\Laravel\withoutVite;

uses(RefreshDatabase::class);

test('xml upload preserves related works for editor prefill', function () {
    $this->actingAs(User::factory()->create());
    withoutVite();

    $xmlPath = base_path('tests/pest/dataset-examples/datacite-example-dataset-v4.xml');
    $xmlContent = file_get_contents($xmlPath);

    expect($xmlContent)->not->toBeFalse();

    $file = UploadedFile::fake()->createWithContent('datacite-example-dataset-v4.xml', (string) $xmlContent);

    $uploadResponse = $this->postJson('/dashboard/upload-xml', ['file' => $file])
        ->assertOk();

    $sessionKey = $uploadResponse->json('sessionKey');

    expect($sessionKey)->toBeString()->not->toBe('');

    $this->get(route('editor', ['xmlSession' => $sessionKey]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('editor')
            ->has('relatedWorks', 4)
            ->where('relatedWorks.0.identifier', 'https://www.nationalgallery.org.uk/research/research-resources/research-papers/improving-our-environment')
            ->where('relatedWorks.0.identifier_type', 'URL')
            ->where('relatedWorks.0.relation_type', 'IsSupplementTo')
            ->where('relatedWorks.1.identifier', 'https://research.ng-london.org.uk/scientific/env/')
            ->where('relatedWorks.1.identifier_type', 'URL')
            ->where('relatedWorks.1.relation_type', 'IsSourceOf')
            ->where('relatedWorks.2.identifier', '10.1080/00393630.2018.1504449/')
            ->where('relatedWorks.2.identifier_type', 'DOI')
            ->where('relatedWorks.2.relation_type', 'IsSupplementedBy')
            ->where('relatedWorks.3.identifier', '10.5281/zenodo.7629200')
            ->where('relatedWorks.3.identifier_type', 'DOI')
            ->where('relatedWorks.3.relation_type', 'IsDocumentedBy')
        );
});

test('xml upload skips related works with invalid type values', function () {
        $this->actingAs(User::factory()->create());
        withoutVite();

        $xml = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<resource xmlns="http://datacite.org/schema/kernel-4">
    <titles>
        <title>Test</title>
    </titles>
    <publicationYear>2026</publicationYear>
    <relatedIdentifiers>
        <relatedIdentifier relatedIdentifierType="URL" relationType="IsSupplementTo">https://example.org/valid</relatedIdentifier>
        <relatedIdentifier relatedIdentifierType="NOT_A_TYPE" relationType="IsSupplementTo">https://example.org/invalid-type</relatedIdentifier>
        <relatedIdentifier relatedIdentifierType="URL" relationType="NOT_A_RELATION">https://example.org/invalid-relation</relatedIdentifier>
    </relatedIdentifiers>
</resource>
XML;

        $file = UploadedFile::fake()->createWithContent('invalid-related-types.xml', $xml);

        $uploadResponse = $this->postJson('/dashboard/upload-xml', ['file' => $file])
                ->assertOk();

        $sessionKey = $uploadResponse->json('sessionKey');

        $this->get(route('editor', ['xmlSession' => $sessionKey]))
                ->assertOk()
                ->assertInertia(fn (Assert $page) => $page
                        ->component('editor')
                        ->has('relatedWorks', 1)
                        ->where('relatedWorks.0.identifier', 'https://example.org/valid')
                        ->where('relatedWorks.0.identifier_type', 'URL')
                        ->where('relatedWorks.0.relation_type', 'IsSupplementTo')
                );
});
