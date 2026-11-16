<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\Resource;
use App\Services\DataCiteModeResolverService;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
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
     * The `prefix` field is only required when registering a *new* DOI.
     * A preassigned DOI keeps its prefix during explicit publication.
     * Ordinary updates never create identifiers and need no prefix selection.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $allowedPrefixes = app(DataCiteModeResolverService::class)->allowedPrefixes($this->user());

        $resource = $this->route('resource');
        $hasExistingDoi = $resource instanceof Resource && $resource->doi !== null && $resource->doi !== '';

        return [
            'prefix' => [
                ! $this->routeIs('resources.publish-doi') || $hasExistingDoi ? 'nullable' : 'required',
                'string',
                Rule::in($allowedPrefixes),
            ],
            'force' => ['sometimes', 'boolean'],
            'confirm_publication' => $this->routeIs('resources.publish-doi') ? ['required', 'accepted'] : ['prohibited'],
        ];
    }

    /**
     * Get custom error messages for validation rules.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        $resolver = app(DataCiteModeResolverService::class);
        $testMode = $resolver->shouldUseTestMode($this->user());
        $allowedPrefixes = $resolver->allowedPrefixes($this->user());

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

    /**
     * Normalize an empty-string prefix to null so the conditional `nullable`
     * rule applied on metadata updates skips the `Rule::in(...)` check when
     * the modal submits without a selection.
     */
    protected function prepareForValidation(): void
    {
        if ($this->has('prefix') && is_string($this->input('prefix')) && trim($this->input('prefix')) === '') {
            $this->merge(['prefix' => null]);
        }
    }
}
