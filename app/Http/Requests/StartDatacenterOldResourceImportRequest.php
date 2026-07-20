<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StartDatacenterOldResourceImportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'datacenter_id' => ['required', 'string', 'max:255'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'datacenter_id.required' => 'Select a datacenter to start the import.',
            'datacenter_id.string' => 'The selected datacenter is invalid.',
            'datacenter_id.max' => 'The selected datacenter is invalid.',
        ];
    }

    protected function prepareForValidation(): void
    {
        $value = $this->input('datacenter_id');

        if (is_string($value)) {
            $value = trim($value);
        }

        $this->merge(['datacenter_id' => $value]);
    }

    public function getDatacenterId(): string
    {
        return (string) $this->input('datacenter_id');
    }
}
