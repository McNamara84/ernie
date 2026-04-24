<?php

declare(strict_types=1);

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Validates that an array of titles contains at least one entry of
 * title_type = "MainTitle" (DataCite 4.7 requirement for relatedItem).
 */
final class HasMainTitle implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (!is_array($value)) {
            $fail('The :attribute must be an array of titles.');

            return;
        }

        foreach ($value as $title) {
            if (!is_array($title)) {
                continue;
            }

            $type = $title['title_type'] ?? $title['titleType'] ?? null;
            $text = $title['title'] ?? null;

            if ($type === 'MainTitle' && is_string($text) && trim($text) !== '') {
                return;
            }
        }

        $fail('At least one non-empty MainTitle is required.');
    }
}
