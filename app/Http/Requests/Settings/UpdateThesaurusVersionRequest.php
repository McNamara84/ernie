<?php

declare(strict_types=1);

namespace App\Http\Requests\Settings;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates payloads for updating a thesaurus version (e.g. ARDC vocabularies).
 *
 * Authorization is gated by `manage-thesauri` (Admin and Group Leader only).
 * The thesaurus type is part of the route, not the request body, so it is not
 * validated here.
 */
class UpdateThesaurusVersionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('manage-thesauri') === true;
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'version' => ['required', 'string', 'max:20', 'regex:/^\d+(-\d+)*$/'],
        ];
    }
}
