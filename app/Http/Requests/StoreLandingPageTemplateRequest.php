<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\Datacenter;
use App\Models\LandingPageTemplate;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreLandingPageTemplateRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true; // Authorization handled by policy in controller
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'filled', 'string', 'max:255', 'unique:landing_page_templates,name'],
            'template_type' => ['sometimes', 'string', Rule::in(LandingPageTemplate::TEMPLATE_TYPES)],
            'datacenter_ids' => ['sometimes', 'array'],
            'datacenter_ids.*' => ['integer', 'distinct', Rule::exists('datacenters', 'id')],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $datacenterIds = $this->input('datacenter_ids', []);
            if (! is_array($datacenterIds) || $datacenterIds === []) {
                return;
            }

            if ($this->input('template_type', LandingPageTemplate::TEMPLATE_TYPE_RESOURCE) === LandingPageTemplate::TEMPLATE_TYPE_IGSN) {
                $validator->errors()->add('datacenter_ids', 'IGSN templates cannot be assigned to datacenters.');

                return;
            }

            if (Datacenter::query()->whereKey($datacenterIds)->where('name', Datacenter::GFZ_NAME)->exists()) {
                $validator->errors()->add('datacenter_ids', 'The canonical GFZ datacenter must remain assigned to the resource system default.');
            }
        });
    }

    /**
     * Prepare the data for validation.
     */
    protected function prepareForValidation(): void
    {
        if ($this->has('name') && is_string($this->input('name'))) {
            $this->merge(['name' => trim($this->input('name'))]);
        }
    }
}
