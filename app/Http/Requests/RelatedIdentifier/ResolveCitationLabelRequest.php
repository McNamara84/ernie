<?php

declare(strict_types=1);

namespace App\Http\Requests\RelatedIdentifier;

use App\Services\DoiSuggestionService;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ResolveCitationLabelRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $doiSuggestions = app(DoiSuggestionService::class);
        $identifierType = $this->string('identifierType')->toString();

        return [
            'identifier' => [
                'required',
                'string',
                'max:2183',
                static function (string $attribute, mixed $value, Closure $fail) use ($doiSuggestions, $identifierType): void {
                    if (! is_string($value)) {
                        return;
                    }

                    if ($identifierType === 'DOI' && ! $doiSuggestions->isValidDoiFormat($value)) {
                        $fail('The :attribute must be a valid DOI.');

                        return;
                    }

                    if ($identifierType !== 'URL') {
                        return;
                    }

                    $scheme = parse_url($value, PHP_URL_SCHEME);

                    if (! filter_var($value, FILTER_VALIDATE_URL) || ! is_string($scheme) || ! in_array(strtolower($scheme), ['http', 'https'], true)) {
                        $fail('The :attribute must be a valid HTTP(S) URL.');
                    }
                },
            ],
            'identifierType' => ['required', 'string', Rule::in(['DOI', 'URL'])],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'identifier' => is_string($this->input('identifier')) ? trim($this->input('identifier')) : $this->input('identifier'),
            'identifierType' => is_string($this->input('identifierType')) ? trim($this->input('identifierType')) : $this->input('identifierType'),
        ]);
    }
}
