<?php

declare(strict_types=1);

namespace App\Http\Requests\Assistance;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates the short-lived token used to bulk-accept matching ROR affiliation suggestions.
 */
class AcceptRorAffiliationMatchesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'bulk_token' => ['required', 'string'],
        ];
    }
}
