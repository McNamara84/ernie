<?php

declare(strict_types=1);

namespace App\Http\Requests\Resource;

use Illuminate\Foundation\Http\FormRequest;

final class DestroyResourceRequest extends FormRequest
{
    public function authorize(): bool
    {
        $resource = $this->route('resource');

        return $this->user()?->can('delete', $resource) ?? false;
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [];
    }
}
