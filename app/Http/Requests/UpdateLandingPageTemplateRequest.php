<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\LandingPageTemplate;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateLandingPageTemplateRequest extends FormRequest
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
        /** @var LandingPageTemplate $template */
        $template = $this->route('landingPageTemplate');
        $allowedLeftColumnSections = LandingPageTemplate::leftColumnSectionsForTemplateType($template->template_type);

        return [
            'name' => ['sometimes', 'filled', 'string', 'min:1', 'max:255', Rule::unique('landing_page_templates', 'name')->ignore($template->id)],
            'right_column_order' => ['sometimes', 'array'],
            'right_column_order.*' => ['required', 'string', Rule::in(LandingPageTemplate::RIGHT_COLUMN_SECTIONS)],
            'left_column_order' => ['sometimes', 'array'],
            'left_column_order.*' => ['required', 'string', Rule::in($allowedLeftColumnSections)],
            'creator_display_limit' => ['sometimes', 'required', 'integer', 'min:'.LandingPageTemplate::MIN_DISPLAY_LIMIT, 'max:'.LandingPageTemplate::MAX_DISPLAY_LIMIT],
            'contributor_display_limit' => ['sometimes', 'required', 'integer', 'min:'.LandingPageTemplate::MIN_DISPLAY_LIMIT, 'max:'.LandingPageTemplate::MAX_DISPLAY_LIMIT],
        ];
    }

    /**
     * Configure the validator instance.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            /** @var LandingPageTemplate $template */
            $template = $this->route('landingPageTemplate');
            $allowedLeftColumnSections = LandingPageTemplate::leftColumnSectionsForTemplateType($template->template_type);

            // Validate right column order contains exactly all valid sections
            if ($this->has('right_column_order') && ! $validator->errors()->has('right_column_order')) {
                $rightOrder = $this->input('right_column_order', []);
                if (is_array($rightOrder) && ! LandingPageTemplate::isValidSectionOrder($rightOrder, LandingPageTemplate::RIGHT_COLUMN_SECTIONS)) {
                    $validator->errors()->add(
                        'right_column_order',
                        'Right column order must contain exactly all valid section keys without duplicates.'
                    );
                }
            }

            // Validate left column order contains exactly all valid sections
            if ($this->has('left_column_order') && ! $validator->errors()->has('left_column_order')) {
                $leftOrder = $this->input('left_column_order', []);
                if (is_array($leftOrder) && ! LandingPageTemplate::isValidSectionOrder($leftOrder, $allowedLeftColumnSections)) {
                    $validator->errors()->add(
                        'left_column_order',
                        'Left column order must contain exactly all valid section keys without duplicates.'
                    );
                }
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

        if ($this->has('right_column_order')) {
            $rightOrder = $this->input('right_column_order');

            if (is_array($rightOrder)) {
                $stringKeys = array_values(array_filter($rightOrder, 'is_string'));

                if (count($stringKeys) === count($rightOrder)) {
                    $this->merge([
                        'right_column_order' => LandingPageTemplate::normalizeRightColumnOrder($stringKeys),
                    ]);
                }
            }
        }
    }
}
