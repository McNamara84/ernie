<?php

declare(strict_types=1);

namespace App\Http\Requests\Datacenter;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates payloads for creating a new datacenter.
 *
 * Authorization is enforced upstream by the `access-editor-settings` route
 * middleware (Admin and Group Leader only); this request only verifies that
 * an authenticated user is present and normalises the `name` input.
 */
class StoreDatacenterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * Trim the `name` input before validation so that `max:255` and
     * `unique:datacenters,name` apply to the stored form.
     */
    protected function prepareForValidation(): void
    {
        $name = $this->input('name');

        if (is_string($name)) {
            $this->merge(['name' => trim($name)]);
        }
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255', 'unique:datacenters,name'],
        ];
    }
}
