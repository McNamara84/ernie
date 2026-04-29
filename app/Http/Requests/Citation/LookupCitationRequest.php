<?php

declare(strict_types=1);

namespace App\Http\Requests\Citation;

use App\Services\DoiSuggestionService;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates the payload for the citation lookup API endpoint used by the
 * Citation Manager UI. Rejects obvious garbage early so upstream Crossref /
 * DataCite calls (and their rate-limit budgets) are not wasted on input that
 * cannot possibly be a DOI.
 */
class LookupCitationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, ValidationRule|string|Closure>>
     */
    public function rules(): array
    {
        $doiSuggestions = app(DoiSuggestionService::class);

        return [
            'doi' => [
                'required',
                'string',
                'max:512',
                static function (string $attribute, mixed $value, Closure $fail) use ($doiSuggestions): void {
                    if (! is_string($value) || ! $doiSuggestions->isValidDoiFormat($value)) {
                        $fail('The :attribute must be a valid DOI (e.g., 10.1234/example).');
                    }
                },
            ],
        ];
    }
}
