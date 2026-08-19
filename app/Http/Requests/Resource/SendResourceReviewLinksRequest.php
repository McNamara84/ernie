<?php

declare(strict_types=1);

namespace App\Http\Requests\Resource;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Authorize and validate batch delivery of resource review links.
 */
final class SendResourceReviewLinksRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('send-review-links') === true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['required', 'integer', 'distinct', 'exists:resources,id'],
        ];
    }
}
