<?php

use App\Models\Resource;
use App\Services\DoiSuggestionService;

beforeEach(function () {
    $this->service = new DoiSuggestionService;
});

describe('DoiSuggestionService - DOI Normalization', function () {
    test('normalizes DOI by trimming whitespace', function () {
        $result = $this->service->normalizeDoi('  10.5880/test.001  ');

        expect($result)->toBe('10.5880/test.001');
    });

    test('removes https://doi.org/ prefix', function () {
        $result = $this->service->normalizeDoi('https://doi.org/10.5880/test.001');

        expect($result)->toBe('10.5880/test.001');
    });

    test('removes http://doi.org/ prefix', function () {
        $result = $this->service->normalizeDoi('http://doi.org/10.5880/test.001');

        expect($result)->toBe('10.5880/test.001');
    });

    test('removes https://dx.doi.org/ prefix', function () {
        $result = $this->service->normalizeDoi('https://dx.doi.org/10.5880/test.001');

        expect($result)->toBe('10.5880/test.001');
    });

    test('keeps DOI unchanged if already normalized', function () {
        $result = $this->service->normalizeDoi('10.5880/test.001');

        expect($result)->toBe('10.5880/test.001');
    });
});

describe('DoiSuggestionService - DOI Format Validation', function () {
    test('validates correct DOI format', function () {
        expect($this->service->isValidDoiFormat('10.5880/test.001'))->toBeTrue();
        expect($this->service->isValidDoiFormat('10.1234/example'))->toBeTrue();
        expect($this->service->isValidDoiFormat('10.14470/rv968923'))->toBeTrue();
    });

    test('validates DOI with URL prefix', function () {
        expect($this->service->isValidDoiFormat('https://doi.org/10.5880/test.001'))->toBeTrue();
    });

    test('rejects invalid DOI format', function () {
        expect($this->service->isValidDoiFormat('not-a-doi'))->toBeFalse();
        expect($this->service->isValidDoiFormat('10/invalid'))->toBeFalse();
        expect($this->service->isValidDoiFormat('doi:10.5880/test'))->toBeFalse();
    });

    test('rejects empty DOI', function () {
        expect($this->service->isValidDoiFormat(''))->toBeFalse();
        expect($this->service->isValidDoiFormat('   '))->toBeFalse();
    });
});

describe('DoiSuggestionService - Check DOI Exists', function () {
    test('returns true when DOI exists', function () {
        Resource::factory()->create(['doi' => '10.5880/existing.001']);

        $result = $this->service->checkDoiExists('10.5880/existing.001');

        expect($result)->toBeTrue();
    });

    test('returns false when DOI does not exist', function () {
        $result = $this->service->checkDoiExists('10.5880/nonexistent.001');

        expect($result)->toBeFalse();
    });

    test('excludes specified resource ID', function () {
        $resource = Resource::factory()->create(['doi' => '10.5880/myresource.001']);

        // Should return false when excluding the resource's own ID
        $result = $this->service->checkDoiExists('10.5880/myresource.001', $resource->id);

        expect($result)->toBeFalse();
    });

    test('still finds duplicate when exclude ID does not match', function () {
        Resource::factory()->create(['doi' => '10.5880/other.001']);

        $result = $this->service->checkDoiExists('10.5880/other.001', 99999);

        expect($result)->toBeTrue();
    });

    test('handles DOI with URL prefix', function () {
        Resource::factory()->create(['doi' => '10.5880/url.001']);

        $result = $this->service->checkDoiExists('https://doi.org/10.5880/url.001');

        expect($result)->toBeTrue();
    });
});

describe('DoiSuggestionService - Get Last Assigned DOI', function () {
    test('returns most recently created DOI', function () {
        Resource::factory()->create([
            'doi' => '10.5880/old.001',
            'created_at' => now()->subDays(2),
        ]);
        Resource::factory()->create([
            'doi' => '10.5880/newest.001',
            'created_at' => now(),
        ]);
        Resource::factory()->create([
            'doi' => '10.5880/older.001',
            'created_at' => now()->subDay(),
        ]);

        $result = $this->service->getLastAssignedDoi();

        expect($result)->toBe('10.5880/newest.001');
    });

    test('returns null when no resources have DOIs', function () {
        Resource::factory()->create(['doi' => null]);

        $result = $this->service->getLastAssignedDoi();

        expect($result)->toBeNull();
    });
});

describe('DoiSuggestionService - Suggest Next DOI', function () {
    test('suggests next for project.year.number pattern', function () {
        $result = $this->service->suggestNextDoi('10.5880/fidgeo.2026.005');

        expect($result)->toBe('10.5880/fidgeo.2026.006');
    });

    test('skips existing DOIs when suggesting', function () {
        Resource::factory()->create(['doi' => '10.5880/test.2026.006']);
        Resource::factory()->create(['doi' => '10.5880/test.2026.007']);

        $result = $this->service->suggestNextDoi('10.5880/test.2026.005');

        expect($result)->toBe('10.5880/test.2026.008');
    });

    test('suggests next for projectdb.number pattern', function () {
        $result = $this->service->suggestNextDoi('10.5880/trr228db.398');

        expect($result)->toBe('10.5880/trr228db.399');
    });

    test('suggests next for gfz.code.year.number pattern', function () {
        $result = $this->service->suggestNextDoi('10.5880/gfz.dmjq.2026.005');

        expect($result)->toBe('10.5880/gfz.dmjq.2026.006');
    });

    test('suggests next for gfz.section.section.year.number pattern', function () {
        $result = $this->service->suggestNextDoi('10.5880/gfz.4.4.2026.003');

        expect($result)->toBe('10.5880/gfz.4.4.2026.004');
    });

    test('suggests next for project.d.year.number pattern', function () {
        $result = $this->service->suggestNextDoi('10.5880/fidgeo.d.2025.002');

        expect($result)->toBe('10.5880/fidgeo.d.2025.003');
    });

    test('suggests next for project-suffix.numbers pattern', function () {
        $result = $this->service->suggestNextDoi('10.5880/gipp-mt.202003.2');

        expect($result)->toBe('10.5880/gipp-mt.202003.3');
    });

    test('suggests next for igets pattern', function () {
        $result = $this->service->suggestNextDoi('10.5880/igets.bu.l1.001');

        expect($result)->toBe('10.5880/igets.bu.l1.002');
    });

    test('pads numbers with zeros for year.number patterns', function () {
        $result = $this->service->suggestNextDoi('10.5880/test.2026.001');

        expect($result)->toBe('10.5880/test.2026.002');
    });

    test('returns null for invalid DOI format', function () {
        $result = $this->service->suggestNextDoi('not-a-doi');

        expect($result)->toBeNull();
    });

    test('generates fallback for unrecognized pattern', function () {
        // A pattern that doesn't match any known pattern but is valid DOI
        $result = $this->service->suggestNextDoi('10.5880/randomsuffix');

        expect($result)->not->toBeNull();
        expect($result)->toStartWith('10.5880/');
    });
});

describe('DoiSuggestionService - Get Resource By DOI', function () {
    test('returns resource info with title', function () {
        $resource = Resource::factory()->create(['doi' => '10.5880/resource.001']);

        // Create main title type and title
        $mainTitleType = \App\Models\TitleType::firstOrCreate(
            ['slug' => 'main-title'],
            ['name' => 'Main Title']
        );
        \App\Models\Title::factory()->create([
            'resource_id' => $resource->id,
            'title_type_id' => $mainTitleType->id,
            'value' => 'Test Resource Title',
        ]);

        $result = $this->service->getResourceByDoi('10.5880/resource.001');

        expect($result)->not->toBeNull();
        expect($result['id'])->toBe($resource->id);
        expect($result['title'])->toBe('Test Resource Title');
    });

    test('returns null title when resource has no main title', function () {
        $resource = Resource::factory()->create(['doi' => '10.5880/notitle.001']);

        $result = $this->service->getResourceByDoi('10.5880/notitle.001');

        expect($result)->not->toBeNull();
        expect($result['id'])->toBe($resource->id);
        expect($result['title'])->toBeNull();
    });

    test('returns null when DOI not found', function () {
        $result = $this->service->getResourceByDoi('10.5880/nonexistent.001');

        expect($result)->toBeNull();
    });

    test('excludes specified resource ID', function () {
        $resource = Resource::factory()->create(['doi' => '10.5880/exclude.001']);

        $result = $this->service->getResourceByDoi('10.5880/exclude.001', $resource->id);

        expect($result)->toBeNull();
    });
});
