<?php

declare(strict_types=1);

use App\Models\Resource;
use App\Models\Subject;
use App\Services\SubjectEnrichment\SubjectEnrichmentMatchInputProvider;

it('filters subject rows whose value only contains whitespace after database selection', function (): void {
    $resource = Resource::factory()->withDoi('10.5880/GFZ.2026.81330')->create();
    Subject::forceCreate([
        'resource_id' => $resource->id,
        'value' => '   ',
        'language' => 'en',
        'subject_scheme' => null,
        'scheme_uri' => null,
        'value_uri' => null,
        'classification_code' => null,
        'breadcrumb_path' => null,
    ]);

    expect((new SubjectEnrichmentMatchInputProvider)->pendingInputs())->toHaveCount(0);
});
