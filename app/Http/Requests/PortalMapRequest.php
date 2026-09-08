<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Http\Requests\Concerns\HasPortalMapRequestRules;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

final class PortalMapRequest extends FormRequest
{
    use HasPortalMapRequestRules;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return $this->portalMapRules();
    }

    public function withValidator(Validator $validator): void
    {
        $this->addPortalMapValidation($validator);
    }

    public function includeExtent(): bool
    {
        return $this->boolean('include_extent');
    }
}
