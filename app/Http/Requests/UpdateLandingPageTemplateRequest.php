<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\Datacenter;
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
            'right_column_order' => ['sometimes', 'required_with:left_column_order', 'array'],
            'right_column_order.*' => ['required', 'string', Rule::in($allowedSections)],
            'left_column_order' => ['sometimes', 'required_with:right_column_order', 'array'],
            'left_column_order.*' => ['required', 'string', Rule::in($allowedSections)],
            'creator_display_limit' => ['sometimes', 'required', 'integer', 'min:'.LandingPageTemplate::MIN_DISPLAY_LIMIT, 'max:'.LandingPageTemplate::MAX_DISPLAY_LIMIT],
            'contributor_display_limit' => ['sometimes', 'required', 'integer', 'min:'.LandingPageTemplate::MIN_DISPLAY_LIMIT, 'max:'.LandingPageTemplate::MAX_DISPLAY_LIMIT],
            'citation_author_display_limit' => ['sometimes', 'required', 'integer', 'min:'.LandingPageTemplate::MIN_DISPLAY_LIMIT, 'max:'.LandingPageTemplate::MAX_DISPLAY_LIMIT],
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
            $datacenterIds = $this->input('datacenter_ids', []);

            if ($this->has('datacenter_ids') && is_array($datacenterIds) && $datacenterIds !== []) {
                $gfz = Datacenter::query()
                    ->whereKey($datacenterIds)
                    ->where('name', Datacenter::GFZ_NAME)
                    ->first(['id', 'landing_page_template_id', 'igsn_landing_page_template_id']);

                if ($gfz !== null) {
                    if ($template->template_type === LandingPageTemplate::TEMPLATE_TYPE_RESOURCE && ! $template->isDefault()) {
                        $validator->errors()->add(
                            'datacenter_ids',
                            'The canonical GFZ datacenter must remain assigned to the Templates Resources copy template.',
                        );
                    }

                    if ($template->template_type === LandingPageTemplate::TEMPLATE_TYPE_IGSN
                        && $template->isDefault()
                        && $gfz->igsn_landing_page_template_id !== $template->id) {
                        $validator->errors()->add(
                            'datacenter_ids',
                            'The Templates IGSN copy template cannot reclaim the GFZ datacenter after it has been assigned to a custom IGSN template.',
                        );
                    }
                }
            }

            if ($this->has('right_column_order') || $this->has('left_column_order')) {
                if ($this->has('left_column_order') && ! $this->has('right_column_order')) {
                    $validator->errors()->add(
                        'right_column_order',
                        'The right column order is required when changing a landing page template layout.'
                    );
                }

                if ($this->has('right_column_order') && ! $this->has('left_column_order')) {
                    $validator->errors()->add(
                        'left_column_order',
                        'The left column order is required when changing a landing page template layout.'
                    );
                }

                $rightOrder = $this->input('right_column_order', []);
                $leftOrder = $this->input('left_column_order', []);
                if (! is_array($rightOrder)
                    || ! is_array($leftOrder)
                    || $validator->errors()->has('right_column_order')
                    || $validator->errors()->has('left_column_order')) {
                    return;
                }

                if ($template->template_type === LandingPageTemplate::TEMPLATE_TYPE_IGSN
                    && ! LandingPageTemplate::isValidIgsnSectionLayout($leftOrder, $rightOrder)) {
                    $validator->errors()->add(
                        'right_column_order',
                        'IGSN columns must contain every valid section exactly once across both columns.'
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
