<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\DataCiteUrlUpdateItemStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class DataCiteUrlUpdateItemsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('update-datacite-landing-page-urls') === true;
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'status' => ['nullable', Rule::enum(DataCiteUrlUpdateItemStatus::class)],
            'issues' => ['nullable', 'boolean'],
            'page' => ['nullable', 'integer', 'min:1'],
        ];
    }

    public function status(): ?DataCiteUrlUpdateItemStatus
    {
        $value = $this->validated('status');

        return is_string($value) ? DataCiteUrlUpdateItemStatus::tryFrom($value) : null;
    }

    public function issuesOnly(): bool
    {
        return filter_var($this->validated('issues', false), FILTER_VALIDATE_BOOL);
    }
}
