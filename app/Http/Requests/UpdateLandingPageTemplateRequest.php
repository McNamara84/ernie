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
        $isIgsn = $template->template_type === LandingPageTemplate::TEMPLATE_TYPE_IGSN;
        $allowedSections = $isIgsn
            ? LandingPageTemplate::IGSN_SECTIONS
            : LandingPageTemplate::RESOURCE_SECTIONS;

        return [
            'name' => ['sometimes', 'filled', 'string', 'min:1', 'max:255', Rule::unique('landing_page_templates', 'name')->ignore($template->id)],
            'right_column_order' => ['sometimes', 'array'],
            'right_column_order.*' => ['required', 'string', Rule::in($allowedSections)],
            'left_column_order' => ['sometimes', 'array'],
            'left_column_order.*' => ['required', 'string', Rule::in($allowedSections)],
            'hidden_sections' => ['sometimes', 'array'],
            'hidden_sections.*' => ['required', 'string', Rule::in($allowedSections)],
            'creator_display_limit' => ['sometimes', 'required', 'integer', 'min:'.LandingPageTemplate::MIN_DISPLAY_LIMIT, 'max:'.LandingPageTemplate::MAX_DISPLAY_LIMIT],
            'contributor_display_limit' => ['sometimes', 'required', 'integer', 'min:'.LandingPageTemplate::MIN_DISPLAY_LIMIT, 'max:'.LandingPageTemplate::MAX_DISPLAY_LIMIT],
            'citation_author_display_limit' => ['sometimes', 'required', 'integer', 'min:'.LandingPageTemplate::MIN_DISPLAY_LIMIT, 'max:'.LandingPageTemplate::MAX_DISPLAY_LIMIT],
            'excluded_date_type_ids' => ['sometimes', 'array'],
            'excluded_date_type_ids.*' => ['required', 'integer', 'distinct', Rule::exists('date_types', 'id')],
            'excluded_relation_type_ids' => ['sometimes', 'array'],
            'excluded_relation_type_ids.*' => ['required', 'integer', 'distinct', Rule::exists('relation_types', 'id')],
            'datacenter_ids' => ['sometimes', 'array'],
            'datacenter_ids.*' => ['integer', 'distinct', Rule::exists('datacenters', 'id')],
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

            if ($this->has('hidden_sections')
                && $template->template_type !== LandingPageTemplate::TEMPLATE_TYPE_IGSN) {
                $validator->errors()->add(
                    'hidden_sections',
                    'Hidden sections are only available for IGSN templates.'
                );
            }

            if ($this->hasAny(['right_column_order', 'left_column_order', 'hidden_sections'])) {
                if (! $this->has('right_column_order')) {
                    $validator->errors()->add(
                        'right_column_order',
                        'The right column order is required when changing a landing page template layout.'
                    );
                }

                if (! $this->has('left_column_order')) {
                    $validator->errors()->add(
                        'left_column_order',
                        'The left column order is required when changing a landing page template layout.'
                    );
                }

                if ($template->template_type === LandingPageTemplate::TEMPLATE_TYPE_IGSN
                    && ! $this->has('hidden_sections')) {
                    $validator->errors()->add(
                        'hidden_sections',
                        'The hidden sections are required when changing an IGSN landing page template layout.'
                    );
                }

                $rightOrder = $this->input('right_column_order', []);
                $leftOrder = $this->input('left_column_order', []);
                $hiddenSections = $this->input('hidden_sections', []);
                if (! is_array($rightOrder)
                    || ! is_array($leftOrder)
                    || ! is_array($hiddenSections)
                    || $validator->errors()->hasAny([
                        'right_column_order',
                        'right_column_order.*',
                        'left_column_order',
                        'left_column_order.*',
                        'hidden_sections',
                        'hidden_sections.*',
                    ])) {
                    return;
                }

                if ($template->template_type === LandingPageTemplate::TEMPLATE_TYPE_IGSN
                    && ! LandingPageTemplate::isValidIgsnSectionLayout($leftOrder, $rightOrder, $hiddenSections)) {
                    $validator->errors()->add(
                        'hidden_sections',
                        'IGSN layout zones must contain every valid section exactly once, and the Version Notice must remain visible.'
                    );
                }

                if ($template->template_type === LandingPageTemplate::TEMPLATE_TYPE_RESOURCE
                    && ! LandingPageTemplate::isValidResourceSectionLayout($leftOrder, $rightOrder)) {
                    $validator->errors()->add(
                        'right_column_order',
                        'Resource columns must contain every valid resource section exactly once and keep metadata sections grouped within each column.'
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
    }
}
