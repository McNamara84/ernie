<?php

declare(strict_types=1);

namespace App\Http\Requests\News;

use Illuminate\Foundation\Http\FormRequest;

final class NewsActionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('manage-news') ?? false;
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return ['revision' => ['required', 'integer', 'min:1'], 'state_revision' => ['required', 'integer', 'min:1']];
    }
}
