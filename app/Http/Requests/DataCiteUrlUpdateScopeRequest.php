<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\DataCiteUrlUpdateScope;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class DataCiteUrlUpdateScopeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('update-datacite-landing-page-urls') === true;
    }

    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        return [
            'scope' => ['required', Rule::enum(DataCiteUrlUpdateScope::class)],
        ];
    }

    public function scope(): DataCiteUrlUpdateScope
    {
        return DataCiteUrlUpdateScope::from((string) $this->validated('scope'));
    }
}
