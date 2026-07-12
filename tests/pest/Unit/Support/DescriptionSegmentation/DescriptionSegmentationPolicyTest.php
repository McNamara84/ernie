<?php

declare(strict_types=1);

use App\Support\DescriptionSegmentation\DescriptionSegmentationPolicy;

covers(DescriptionSegmentationPolicy::class);

it('exposes the supported description segmentation policy constants', function (): void {
    $policy = new DescriptionSegmentationPolicy;

    expect($policy->policyVersion())->toBe('issue-815-v1')
        ->and($policy->requiresStructuralEvidence())->toBeTrue()
        ->and($policy->supportedTargetSlugs())->toBe([
            'Methods',
            'TechnicalInfo',
            'TableOfContents',
            'SeriesInformation',
        ])
        ->and($policy->lowConfidenceTargetSlugs())->toBe([
            'SeriesInformation',
        ])
        ->and($policy->excludedTargetSlugs())->toBe([
            'Abstract',
            'Other',
        ])
        ->and($policy->structuralEvidenceTypes())->toBe([
            'heading',
            'labelled_section',
            'list_structure',
            'paragraph_boundary',
            'file_inventory',
            'version_block',
        ])
        ->and($policy->nonStructuralEvidenceTypes())->toBe([
            'keyword_match',
        ]);
});

it('canonicalizes description type identifiers from database slugs and UI labels', function (): void {
    $policy = new DescriptionSegmentationPolicy;

    expect($policy->canonicalTypeSlug('TechnicalInfo'))->toBe('TechnicalInfo')
        ->and($policy->canonicalTypeSlug('technical-info'))->toBe('TechnicalInfo')
        ->and($policy->canonicalTypeSlug('Technical Info'))->toBe('TechnicalInfo')
        ->and($policy->canonicalTypeSlug('technical_information'))->toBe('TechnicalInfo')
        ->and($policy->canonicalTypeSlug('table-of-contents'))->toBe('TableOfContents')
        ->and($policy->canonicalTypeSlug('Series Information'))->toBe('SeriesInformation')
        ->and($policy->canonicalTypeSlug('methods'))->toBe('Methods')
        ->and($policy->canonicalTypeSlug('abstract'))->toBe('Abstract')
        ->and($policy->canonicalTypeSlug('Other'))->toBe('Other')
        ->and($policy->canonicalTypeSlug('UsageNotes'))->toBeNull();
});

it('classifies supported, low confidence, and excluded targets', function (): void {
    $policy = new DescriptionSegmentationPolicy;

    expect($policy->isSourceTypeSupported('Abstract'))->toBeTrue()
        ->and($policy->isSourceTypeSupported('Methods'))->toBeFalse()
        ->and($policy->isSupportedTarget('Methods'))->toBeTrue()
        ->and($policy->isSupportedTarget('Technical Info'))->toBeTrue()
        ->and($policy->isSupportedTarget('TableOfContents'))->toBeTrue()
        ->and($policy->isSupportedTarget('SeriesInformation'))->toBeTrue()
        ->and($policy->isSupportedTarget('Other'))->toBeFalse()
        ->and($policy->isLowConfidenceTarget('Series Information'))->toBeTrue()
        ->and($policy->isLowConfidenceTarget('Methods'))->toBeFalse()
        ->and($policy->isExcludedTarget('Abstract'))->toBeTrue()
        ->and($policy->isExcludedTarget('Other'))->toBeTrue()
        ->and($policy->confidenceLevelForTarget('Methods'))->toBe('medium')
        ->and($policy->confidenceLevelForTarget('TechnicalInfo'))->toBe('medium')
        ->and($policy->confidenceLevelForTarget('TableOfContents'))->toBe('medium')
        ->and($policy->confidenceLevelForTarget('SeriesInformation'))->toBe('low')
        ->and($policy->confidenceLevelForTarget('Other'))->toBeNull()
        ->and($policy->confidenceLevelForTarget('Unknown'))->toBeNull();
});

it('distinguishes structural evidence from keyword only matches', function (): void {
    $policy = new DescriptionSegmentationPolicy;

    expect($policy->isStructuralEvidence('heading'))->toBeTrue()
        ->and($policy->isStructuralEvidence('Labelled Section'))->toBeTrue()
        ->and($policy->isStructuralEvidence('list-structure'))->toBeTrue()
        ->and($policy->isStructuralEvidence('paragraph_boundary'))->toBeTrue()
        ->and($policy->isStructuralEvidence('File Inventory'))->toBeTrue()
        ->and($policy->isStructuralEvidence('version block'))->toBeTrue()
        ->and($policy->isStructuralEvidence('keyword_match'))->toBeFalse()
        ->and($policy->hasStructuralEvidence(['keyword_match']))->toBeFalse()
        ->and($policy->hasStructuralEvidence(['keyword_match', 'file_inventory']))->toBeTrue()
        ->and($policy->hasStructuralEvidence([]))->toBeFalse();
});

it('allows suggestions only from Abstract sources to supported targets with structural evidence', function (): void {
    $policy = new DescriptionSegmentationPolicy;

    expect($policy->canSuggest('Abstract', 'Methods', ['heading']))->toBeTrue()
        ->and($policy->canSuggest('Abstract', 'TechnicalInfo', ['file_inventory']))->toBeTrue()
        ->and($policy->canSuggest('Abstract', 'TableOfContents', ['list_structure']))->toBeTrue()
        ->and($policy->canSuggest('Abstract', 'SeriesInformation', ['labelled_section']))->toBeTrue()
        ->and($policy->canSuggest('Abstract', 'Methods', ['keyword_match']))->toBeFalse()
        ->and($policy->canSuggest('Methods', 'TechnicalInfo', ['heading']))->toBeFalse()
        ->and($policy->canSuggest('Abstract', 'Other', ['heading']))->toBeFalse()
        ->and($policy->canSuggest('Abstract', 'Abstract', ['heading']))->toBeFalse();
});

it('reports suppression reasons for unsupported source target and evidence combinations', function (): void {
    $policy = new DescriptionSegmentationPolicy;

    expect($policy->suppressionReasons('Methods', 'TechnicalInfo', ['heading']))->toBe([
        'source_type_not_abstract',
    ])
        ->and($policy->suppressionReasons('Abstract', 'Other', ['heading']))->toBe([
            'target_type_excluded',
        ])
        ->and($policy->suppressionReasons('Abstract', 'UsageNotes', ['heading']))->toBe([
            'target_type_not_supported',
        ])
        ->and($policy->suppressionReasons('Abstract', 'Methods', ['keyword_match']))->toBe([
            'structural_evidence_required',
        ])
        ->and($policy->suppressionReasons('Methods', 'Other', ['keyword_match']))->toBe([
            'source_type_not_abstract',
            'target_type_excluded',
            'structural_evidence_required',
        ]);
});

it('exposes conservative minimum text lengths for future discovery implementations', function (): void {
    $policy = new DescriptionSegmentationPolicy;

    expect($policy->minimumTextLengths())->toBe([
        'source' => 600,
        'segment' => 80,
        'remaining_abstract' => 120,
    ]);
});
