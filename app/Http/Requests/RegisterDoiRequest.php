<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Config;
use Illuminate\Validation\Rule;

/**
 * Request validation for DOI registration with DataCite
 */
class RegisterDoiRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        // Authorization is handled by middleware and route model binding
        // Users who can access the route can register DOIs
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        // Get allowed prefixes based on test mode
        $testMode = (bool) Config::get('datacite.test_mode', true);
        $allowedPrefixes = $testMode
            ? Config::get('datacite.test.prefixes', [])
            : Config::get('datacite.production.prefixes', []);

        return [
            'prefix' => [
                'required',
                'string',
                Rule::in($allowedPrefixes),
            ],
        ];
    }

    /**
     * Get custom error messages for validation rules.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        $testMode = (bool) Config::get('datacite.test_mode', true);
        $allowedPrefixes = $testMode
            ? Config::get('datacite.test.prefixes', [])
            : Config::get('datacite.production.prefixes', []);

        $mode = $testMode ? 'test' : 'production';
        $prefixList = implode(', ', $allowedPrefixes);

        return [
            'prefix.required' => 'A DOI prefix must be selected.',
            'prefix.in' => "Invalid DOI prefix for {$mode} mode. Allowed prefixes: {$prefixList}",
        ];
    }

    /**
     * Get custom attributes for validator errors.
     *
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'prefix' => 'DOI prefix',
        ];
    }
}
