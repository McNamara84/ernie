<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Support\IgsnIdentifier;
use Illuminate\Foundation\Http\FormRequest;

class StartSingleIgsnImportRequest extends FormRequest
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
            'igsn' => [
                'required',
                'string',
                'max:255',
                function (string $attribute, mixed $value, \Closure $fail): void {
                    if (! is_string($value)) {
                        return;
                    }

                    if (IgsnIdentifier::normalizeInputToDoi($value) === null) {
                        $fail('Enter a valid IGSN handle or DOI using the configured IGSN prefix.');
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
            'igsn.required' => 'An IGSN is required to import a single sample.',
            'igsn.string' => 'The IGSN must be a string.',
            'igsn.max' => 'The IGSN must not exceed 255 characters.',
        ];
    }

    protected function prepareForValidation(): void
    {
        $normalizedDoi = IgsnIdentifier::normalizeInputToDoi($this->input('igsn'));

        $this->merge([
            'igsn' => $normalizedDoi ?? $this->input('igsn'),
        ]);
    }

    public function getDoi(): string
    {
        return (string) IgsnIdentifier::normalizeInputToDoi($this->input('igsn'));
    }

    public function getHandle(): string
    {
        return (string) IgsnIdentifier::handleFromDoi($this->getDoi());
    }
}
