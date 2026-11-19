<?php

declare(strict_types=1);

namespace App\Http\Requests\News;

use Illuminate\Foundation\Http\FormRequest;

final class PreviewNewsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('manage-news') ?? false;
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return ['body_markdown' => ['nullable', 'string', 'max:100000']];
    }
}
