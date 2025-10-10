<?php

/**
 * Unit tests for StoreResourceRequest validation rules for controlled keywords
 * These tests verify that the validation rules are correctly defined without database dependencies
 */

use App\Http\Requests\StoreResourceRequest;

it('has validation rules for gcmdKeywords', function (): void {
    $request = new StoreResourceRequest();
    $rules = $request->rules();
    
    expect($rules)->toHaveKey('gcmdKeywords')
        ->and($rules)->toHaveKey('gcmdKeywords.*.id')
        ->and($rules)->toHaveKey('gcmdKeywords.*.text')
        ->and($rules)->toHaveKey('gcmdKeywords.*.path')
        ->and($rules)->toHaveKey('gcmdKeywords.*.language')
        ->and($rules)->toHaveKey('gcmdKeywords.*.scheme')
        ->and($rules)->toHaveKey('gcmdKeywords.*.schemeURI')
        ->and($rules)->toHaveKey('gcmdKeywords.*.vocabularyType');
});

it('validates gcmdKeywords as nullable array', function (): void {
    $request = new StoreResourceRequest();
    $rules = $request->rules();
    
    expect($rules['gcmdKeywords'])->toContain('nullable')
        ->and($rules['gcmdKeywords'])->toContain('array');
});

it('requires id, text, path, and vocabularyType for each keyword', function (): void {
    $request = new StoreResourceRequest();
    $rules = $request->rules();
    
    // ID is required and string
    expect($rules['gcmdKeywords.*.id'])->toContain('required')
        ->and($rules['gcmdKeywords.*.id'])->toContain('string');
    
    // Text is required and string
    expect($rules['gcmdKeywords.*.text'])->toContain('required')
        ->and($rules['gcmdKeywords.*.text'])->toContain('string');
    
    // Path is required and string
    expect($rules['gcmdKeywords.*.path'])->toContain('required')
        ->and($rules['gcmdKeywords.*.path'])->toContain('string');
    
    // Vocabulary type is required and must be in specific values
    expect($rules['gcmdKeywords.*.vocabularyType'])->toContain('required')
        ->and($rules['gcmdKeywords.*.vocabularyType'])->toContain('string');
});

it('allows nullable language, scheme, and schemeURI', function (): void {
    $request = new StoreResourceRequest();
    $rules = $request->rules();
    
    expect($rules['gcmdKeywords.*.language'])->toContain('nullable');
    expect($rules['gcmdKeywords.*.scheme'])->toContain('nullable');
    expect($rules['gcmdKeywords.*.schemeURI'])->toContain('nullable');
});

it('validates vocabulary type enum values', function (): void {
    $request = new StoreResourceRequest();
    $rules = $request->rules();
    
    // Check that vocabularyType has a Rule::in() constraint
    $vocabularyTypeRules = $rules['gcmdKeywords.*.vocabularyType'];
    
    expect($vocabularyTypeRules)->toBeArray();
    
    // Find the Rule::in() rule
    $hasInRule = false;
    foreach ($vocabularyTypeRules as $rule) {
        if ($rule instanceof \Illuminate\Validation\Rules\In) {
            $hasInRule = true;
            break;
        }
    }
    
    expect($hasInRule)->toBeTrue('Should have Rule::in() for vocabulary type validation');
});

it('validates keyword_id max length of 512', function (): void {
    $request = new StoreResourceRequest();
    $rules = $request->rules();
    
    // ID should have max:512 validation
    $idRules = $rules['gcmdKeywords.*.id'];
    
    $hasMaxRule = false;
    foreach ($idRules as $rule) {
        if (is_string($rule) && str_contains($rule, 'max:')) {
            $hasMaxRule = true;
            expect($rule)->toContain('max:512');
            break;
        }
    }
    
    expect($hasMaxRule)->toBeTrue('Should have max:512 validation for keyword_id');
});

it('validates text max length of 255', function (): void {
    $request = new StoreResourceRequest();
    $rules = $request->rules();
    
    $textRules = $rules['gcmdKeywords.*.text'];
    
    $hasMaxRule = false;
    foreach ($textRules as $rule) {
        if (is_string($rule) && str_contains($rule, 'max:')) {
            $hasMaxRule = true;
            expect($rule)->toContain('max:255');
            break;
        }
    }
    
    expect($hasMaxRule)->toBeTrue('Should have max:255 validation for text');
});

it('validates scheme max length of 255', function (): void {
    $request = new StoreResourceRequest();
    $rules = $request->rules();
    
    $schemeRules = $rules['gcmdKeywords.*.scheme'];
    
    $hasMaxRule = false;
    foreach ($schemeRules as $rule) {
        if (is_string($rule) && str_contains($rule, 'max:')) {
            $hasMaxRule = true;
            expect($rule)->toContain('max:255');
            break;
        }
    }
    
    expect($hasMaxRule)->toBeTrue('Should have max:255 validation for scheme');
});

it('validates schemeURI max length of 512', function (): void {
    $request = new StoreResourceRequest();
    $rules = $request->rules();
    
    $schemeURIRules = $rules['gcmdKeywords.*.schemeURI'];
    
    $hasMaxRule = false;
    foreach ($schemeURIRules as $rule) {
        if (is_string($rule) && str_contains($rule, 'max:')) {
            $hasMaxRule = true;
            expect($rule)->toContain('max:512');
            break;
        }
    }
    
    expect($hasMaxRule)->toBeTrue('Should have max:512 validation for schemeURI');
});

it('validates language max length of 10', function (): void {
    $request = new StoreResourceRequest();
    $rules = $request->rules();
    
    $languageRules = $rules['gcmdKeywords.*.language'];
    
    $hasMaxRule = false;
    foreach ($languageRules as $rule) {
        if (is_string($rule) && str_contains($rule, 'max:')) {
            $hasMaxRule = true;
            expect($rule)->toContain('max:10');
            break;
        }
    }
    
    expect($hasMaxRule)->toBeTrue('Should have max:10 validation for language');
});
