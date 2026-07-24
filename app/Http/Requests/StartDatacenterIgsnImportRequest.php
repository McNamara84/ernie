<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\Resource;
use Illuminate\Foundation\Http\FormRequest;

class StartDatacenterIgsnImportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('importFromDataCite', Resource::class) ?? false;
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'datacenter_id' => ['required', 'string', 'max:100', 'regex:/\AIGSNDB\.[A-Z0-9_-]+\z/'],
        ];
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('datacenter_id'))) {
            $this->merge(['datacenter_id' => trim($this->input('datacenter_id'))]);
        }
    }

    public function getDatacenterId(): string
    {
        return (string) $this->validated('datacenter_id');
    }
}
