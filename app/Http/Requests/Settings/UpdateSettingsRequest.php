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
            'resourceTypes.*.is_active' => ['required', 'boolean'],
            'resourceTypes.*.is_elmo_active' => ['required', 'boolean'],
            'titleTypes' => ['required', 'array'],
            'titleTypes.*.id' => ['required', 'integer', 'exists:title_types,id'],
            'titleTypes.*.name' => ['required', 'string'],
            'titleTypes.*.slug' => ['required', 'string'],
            'titleTypes.*.is_active' => ['required', 'boolean'],
            'titleTypes.*.is_elmo_active' => ['required', 'boolean'],
            'rights' => ['required', 'array'],
            'rights.*.id' => ['required', 'integer', 'exists:rights,id'],
            'rights.*.is_active' => ['required', 'boolean'],
            'rights.*.is_elmo_active' => ['required', 'boolean'],
            'languages' => ['required', 'array'],
            'languages.*.id' => ['required', 'integer', 'exists:languages,id'],
            'languages.*.active' => ['required', 'boolean'],
            'languages.*.elmo_active' => ['required', 'boolean'],
            'dateTypes' => ['required', 'array'],
            'dateTypes.*.id' => ['required', 'integer', 'exists:date_types,id'],
            'dateTypes.*.is_active' => ['required', 'boolean'],
            'maxTitles' => ['required', 'integer', 'min:1'],
            'maxLicenses' => ['required', 'integer', 'min:1'],
        ];
    }
}
