<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class AssignGuidedToursRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'tour_ids' => ['required', 'array', 'min:1'],
            'tour_ids.*' => ['required', 'integer', 'distinct'],
        ];
    }
}