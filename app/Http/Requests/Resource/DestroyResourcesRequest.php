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

        $seenIntegerIds = [];
        $normalizedIds = [];
        foreach ($ids as $id) {
            $integerId = $this->normalizeIntegerId($id);

            if ($integerId === null) {
                $normalizedIds[] = $id;

                continue;
            }

            if (isset($seenIntegerIds[$integerId])) {
                continue;
            }

            $seenIntegerIds[$integerId] = true;
            $normalizedIds[] = $integerId;
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

    private function normalizeIntegerId(mixed $id): ?int
    {
        if (! is_int($id) && ! is_string($id)) {
            return null;
        }

        $integerId = filter_var($id, FILTER_VALIDATE_INT);

        return $integerId === false ? null : $integerId;
    }
}
