<?php

declare(strict_types=1);

namespace App\Http\Requests\LandingPage;

use App\Enums\TombstoneReason;
use App\Models\LandingPage;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ResourceTombstoneRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('manageTombstone', LandingPage::class) === true;
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('statement'))) {
            $this->merge(['statement' => trim($this->input('statement'))]);
        }
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $rules = ['revision' => ['required', 'integer', 'min:0']];
        if ($this->routeIs('landing-page.tombstone.activate', 'landing-page.tombstone.update')) {
            $rules['reason'] = ['required', Rule::enum(TombstoneReason::class)];
            $rules['statement'] = ['required', 'string', 'max:5000'];
        }
        if ($this->routeIs('landing-page.tombstone.activate', 'landing-page.tombstone.restore')) {
            $rules['confirmed'] = ['required', 'accepted'];
        }
        if ($this->routeIs('landing-page.tombstone.restore')) {
            $rules['restore_published'] = ['sometimes', 'boolean'];
        }

        return $rules;
    }
}
