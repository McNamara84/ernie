<?php

declare(strict_types=1);

namespace App\Http\Requests\Datacenter;

use Illuminate\Foundation\Http\FormRequest;

class UpdateDatacenterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    protected function prepareForValidation(): void
    {
        $name = $this->input('name');
        if (is_string($name)) {
            $this->merge(['name' => trim($name)]);
        }
    }

    /** @return array<string, array<int, string>> */
    public function rules(): array
    {
        return ['name' => ['required', 'string', 'max:255']];
    }
}
