<?php

declare(strict_types=1);

namespace App\Http\Requests\Settings;

use Illuminate\Foundation\Http\FormRequest;

class StoreThesaurusReviewCompletionRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        return $user !== null && $user->can('manage-thesauri') && $user->can('access-editor-settings');
    }

    /** @return array<string, array<int, string>> */
    public function rules(): array
    {
        return ['period' => ['required', 'string', 'regex:/\A[0-9]{4}-Q[1-4]\z/']];
    }
}
