<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Http\Requests\Concerns\HasIgsnPortalFilterRules;
use Illuminate\Foundation\Http\FormRequest;

final class PortalSearchRequest extends FormRequest
{
    use HasIgsnPortalFilterRules;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return $this->igsnPortalFilterRules();
    }
}
