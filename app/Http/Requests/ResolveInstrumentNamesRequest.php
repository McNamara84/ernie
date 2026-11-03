<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class ResolveInstrumentNamesRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'instruments' => ['present', 'array', 'max:'.StoreResourceRequest::MAX_REPEATABLE_METADATA_ITEMS],
            'instruments.*.pid' => ['required', 'string', 'max:512'],
            'instruments.*.pidType' => ['required', 'string', Rule::in(['Handle', 'DOI', 'URL'])],
            'instruments.*.name' => ['present', 'nullable', 'string', 'max:1024'],
        ];
    }
}
