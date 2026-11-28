<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class IdentifierValidationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'identifierType' => ['required', 'string', 'in:SWHID'],
            'identifier' => ['required', 'string', 'max:2183'],
        ];
    }
}
