<?php

declare(strict_types=1);

namespace App\Http\Requests\Resource;

use App\Http\Requests\Resource\Concerns\ResolvesResourceListing;
use App\Models\Resource;
use Illuminate\Foundation\Http\FormRequest;

final class IndexResourcesRequest extends FormRequest
{
    use ResolvesResourceListing;

    protected function prepareForValidation(): void
    {
        $this->normaliseListingInput();
    }

    public function authorize(): bool
    {
        return $this->user()?->can('viewAny', Resource::class) ?? false;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return $this->listingRules();
    }
}
