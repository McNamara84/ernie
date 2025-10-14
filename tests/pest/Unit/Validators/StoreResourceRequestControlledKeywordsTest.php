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
        ->and($rules)->toHaveKey('gcmdKeywords.*.schemeURI');
});

it('validates gcmdKeywords as nullable array', function (): void {
    $request = new StoreResourceRequest();
    $rules = $request->rules();
    
    expect($rules['gcmdKeywords'])->toContain('nullable')
        ->and($rules['gcmdKeywords'])->toContain('array');
});

it('requires id, text, path, and scheme for each keyword', function (): void {
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
    
    // Scheme is required and string (changed from nullable)
    expect($rules['gcmdKeywords.*.scheme'])->toContain('required')
        ->and($rules['gcmdKeywords.*.scheme'])->toContain('string');
});

it('allows nullable language and schemeURI', function (): void {
    $request = new StoreResourceRequest();
    $rules = $request->rules();
    
    expect($rules['gcmdKeywords.*.language'])->toContain('nullable');
    expect($rules['gcmdKeywords.*.schemeURI'])->toContain('nullable');
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
