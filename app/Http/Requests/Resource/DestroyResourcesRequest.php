<?php

declare(strict_types=1);

namespace App\Http\Requests\Resource;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

final class DestroyResourcesRequest extends FormRequest
{
    public const MAX_BATCH_SIZE = 100;

    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    protected function prepareForValidation(): void
    {
        $ids = $this->input('ids');

        if (! is_array($ids)) {
            return;
        }

        $normalizedIds = [];
        foreach ($ids as $id) {
            if (! in_array($id, $normalizedIds, false)) {
                $normalizedIds[] = $id;
            }
        }

        $this->merge([
            'ids' => $normalizedIds,
        ]);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'ids' => ['required', 'array', 'min:1', 'max:'.self::MAX_BATCH_SIZE],
            'ids.*' => ['required', 'integer'],
        ];
    }
}
