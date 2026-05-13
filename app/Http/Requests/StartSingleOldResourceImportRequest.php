<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Services\DoiSuggestionService;
use Illuminate\Foundation\Http\FormRequest;

class StartSingleOldResourceImportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, string|\Closure>>
     */
    public function rules(): array
    {
        return [
            'doi' => [
                'required',
                'string',
                'max:255',
                function (string $attribute, mixed $value, \Closure $fail): void {
                    if (! is_string($value)) {
                        return;
                    }

                    if (! app(DoiSuggestionService::class)->isValidDoiFormat($value)) {
                        $fail('Enter a valid DOI in the format 10.xxxx/... or https://doi.org/10.xxxx/....');
                    }
                },
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'doi.required' => 'A DOI is required to import a single legacy resource.',
            'doi.string' => 'The DOI must be a string.',
            'doi.max' => 'The DOI must not exceed 255 characters.',
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'doi' => $this->normalizeDoiInput($this->input('doi')),
        ]);
    }

    public function getDoi(): string
    {
        return (string) $this->input('doi');
    }

    private function normalizeDoiInput(mixed $input): mixed
    {
        if ($input === null) {
            return null;
        }

        if (is_numeric($input)) {
            $input = (string) $input;
        }

        if (! is_string($input)) {
            return $input;
        }

        $normalized = app(DoiSuggestionService::class)->normalizeDoi($input);

        return $normalized !== '' ? $normalized : null;
    }
}