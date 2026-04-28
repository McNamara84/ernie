<?php

declare(strict_types=1);

namespace App\Http\Requests\Settings;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates payloads for updating a thesaurus version (e.g. ARDC vocabularies).
 *
 * Authorization is gated by `manage-thesauri` (Admin and Group Leader only) at
 * the route level (`Route::middleware(['can:manage-thesauri'])`), so the
 * `authorize()` method here is intentionally permissive — by the time this
 * FormRequest is resolved, the route middleware has already rejected anyone
 * without the gate. The thesaurus type is part of the route, not the request
 * body, so it is not validated here.
 */
class UpdateThesaurusVersionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
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
