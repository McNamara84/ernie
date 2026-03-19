<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class RorResolveRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'names' => ['required', 'array', 'min:1', 'max:20'],
            'names.*' => ['required', 'string', 'max:500'],
        ];
    }
}
