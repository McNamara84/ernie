<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Request validation for DOI validation API endpoint.
 */
class ValidateDoiRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'doi' => ['required', 'string', 'max:255'],
            'exclude_resource_id' => ['nullable', 'integer', 'min:1'],
        ];
    }

    /**
     * Get custom messages for validator errors.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'doi.required' => 'A DOI is required for validation.',
            'doi.string' => 'The DOI must be a string.',
            'doi.max' => 'The DOI must not exceed 255 characters.',
            'exclude_resource_id.integer' => 'The resource ID must be an integer.',
            'exclude_resource_id.min' => 'The resource ID must be a positive integer.',
        ];
    }

    /**
     * Get the DOI from the request, trimmed.
     */
    public function getDoi(): string
    {
        return trim($this->string('doi')->toString());
    }

    /**
     * Get the resource ID to exclude, if provided.
     */
    public function getExcludeResourceId(): ?int
    {
        $value = $this->input('exclude_resource_id');

        return $value !== null ? (int) $value : null;
    }
}
