<?php

namespace App\Http\Requests\Settings;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class UpdateSettingsRequest extends FormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'resourceTypes' => ['required', 'array'],
            'resourceTypes.*.id' => ['required', 'integer', 'exists:resource_types,id'],
            'resourceTypes.*.name' => ['required', 'string'],
            'resourceTypes.*.active' => ['required', 'boolean'],
            'resourceTypes.*.elmo_active' => ['required', 'boolean'],
            'titleTypes' => ['required', 'array'],
            'titleTypes.*.id' => ['required', 'integer', 'exists:title_types,id'],
            'titleTypes.*.name' => ['required', 'string'],
            'titleTypes.*.slug' => ['required', 'string'],
            'titleTypes.*.active' => ['required', 'boolean'],
            'titleTypes.*.elmo_active' => ['required', 'boolean'],
            'licenses' => ['required', 'array'],
            'licenses.*.id' => ['required', 'integer', 'exists:rights,id'],
            'licenses.*.active' => ['required', 'boolean'],
            'licenses.*.elmo_active' => ['required', 'boolean'],
            'languages' => ['required', 'array'],
            'languages.*.id' => ['required', 'integer', 'exists:languages,id'],
            'languages.*.active' => ['required', 'boolean'],
            'languages.*.elmo_active' => ['required', 'boolean'],
            'dateTypes' => ['required', 'array'],
            'dateTypes.*.id' => ['required', 'integer', 'exists:date_types,id'],
            'dateTypes.*.active' => ['required', 'boolean'],
            'maxTitles' => ['required', 'integer', 'min:1'],
            'maxLicenses' => ['required', 'integer', 'min:1'],
        ];
    }
}
