<?php

declare(strict_types=1);

namespace App\Http\Requests\Settings;

use App\Services\Assistance\AssistantRegistrar;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateAssistanceAccordionRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Prepare the data for validation.
     */
    protected function prepareForValidation(): void
    {
        if (! $this->has('collapsed_assistant_ids')) {
            $this->merge(['collapsed_assistant_ids' => []]);
        }
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        /** @var AssistantRegistrar $registrar */
        $registrar = app(AssistantRegistrar::class);

        return [
            'collapsed_assistant_ids' => ['present', 'array'],
            'collapsed_assistant_ids.*' => ['string', 'distinct', Rule::in(array_keys($registrar->getAll()))],
        ];
    }
}
