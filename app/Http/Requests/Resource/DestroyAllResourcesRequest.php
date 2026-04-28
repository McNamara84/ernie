<?php

declare(strict_types=1);

namespace App\Http\Requests\Resource;

use Illuminate\Foundation\Http\FormRequest;

final class DestroyAllResourcesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('delete-all-resources') ?? false;
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'confirmation' => ['required', 'string', 'in:delete'],
        ];
    }
}
