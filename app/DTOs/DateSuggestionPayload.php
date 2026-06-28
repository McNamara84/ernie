<?php

declare(strict_types=1);

namespace App\DTOs;

final readonly class DateSuggestionPayload
{
    public string \;
    public string \;
    public string \;
    public string \;
    public bool \;

    public function __construct(
        string \,
        string \,
        string \,
        string \,
        bool \
    ) {
        \->suggestion_kind = \;
        \->normalized_date_value = \;
        \->proposed_date_type = \;
        \->confidence = \;
        \->is_ambiguous = \;
    }

    public function toPayloadArray(): array
    {
        return [
            'suggestion_kind'       => \->suggestion_kind,
            'normalized_date_value' => \->normalized_date_value,
            'proposed_date_type'    => \->proposed_date_type,
            'confidence'            => \->confidence,
            'is_ambiguous'          => \->is_ambiguous,
        ];
    }
}
