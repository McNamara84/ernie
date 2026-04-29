<?php

declare(strict_types=1);

namespace App\Http\Requests\Doi;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates the payload for the legacy POST /api/validate-doi endpoint, which
 * resolves a DOI against DataCite and falls back to doi.org. The DOI itself
 * may be a bare DOI or a resolver URL; the controller normalises it.
 */
class ResolveDoiRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'doi' => ['required', 'string'],
        ];
    }
}
