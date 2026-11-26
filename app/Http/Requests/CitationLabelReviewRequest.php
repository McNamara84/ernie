<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CitationLabelReviewRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, string> */
    public function rules(): array
    {
        return $this->routeIs('resources.citation-labels.apply')
            ? ['tokens' => 'required|array|list|min:1|max:50', 'tokens.*' => 'required|string|distinct']
            : ['ids' => 'required|array|list|min:1|max:100', 'ids.*' => 'integer|min:1|distinct', 'cursor' => 'nullable|integer|min:0', 'review_id' => 'nullable|uuid'];
    }
}
