<?php

declare(strict_types=1);

namespace App\Http\Requests\Batch;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Authorizes and validates batch registration of IGSNs at DataCite.
 */
class RegisterIgsnsRequest extends FormRequest
{
    /**
     * Maximum number of IGSNs that can be registered in a single batch.
     */
    public const MAX_BATCH_SIZE = 25;

    public function authorize(): bool
    {
        return $this->user()?->can('register-production-doi') === true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'ids' => ['required', 'array', 'min:1', 'max:'.self::MAX_BATCH_SIZE],
            'ids.*' => ['required', 'integer', 'exists:resources,id'],
        ];
    }
}
