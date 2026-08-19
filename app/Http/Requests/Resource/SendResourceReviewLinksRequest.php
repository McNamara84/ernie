<?php

declare(strict_types=1);

namespace App\Http\Requests\Resource;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Authorize and validate batch delivery of resource review links.
 */
final class SendResourceReviewLinksRequest extends FormRequest
{
    /**
     * Maximum number of resources whose review links can be sent at once.
     */
    public const MAX_BATCH_SIZE = 100;

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
            'ids' => ['required', 'array', 'min:1', 'max:'.self::MAX_BATCH_SIZE],
            'ids.*' => ['required', 'integer', 'distinct', 'exists:resources,id'],
        ];
    }
}
