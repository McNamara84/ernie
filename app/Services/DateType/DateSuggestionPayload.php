<?php

declare(strict_types=1);

namespace App\Services\DateType;

/**
 * Official Data Contract for Date Suggestions (Epic #767 - Task 3).
 * This DTO defines the strict payload structure required by the frontend
 * and anchors the implementations of Task 1 and Task 2.
 */
final readonly class DateSuggestionPayload
{
    /** @var string 'CORRECTION' or 'ADDITION' (Populated by Task 1 Logic) */
    public string $suggestion_kind;

    /** @var string Normalized standard date string (Populated by Task 2 Rules) */
    public string $normalized_date_value;

    /** @var string Target DataCite type: 'Collected'|'Coverage'|'Created'|'Issued' */
    public string $proposed_date_type;

    /** @var string Data evaluation quality: 'HIGH'|'MEDIUM'|'LOW' (From Task 2) */
    public string $confidence;

    /** @var bool Flag set to true if conflicting evidence is caught in Task 2 */
    public bool $is_ambiguous;

    public function __construct(
        string $suggestion_kind,
        string $normalized_date_value,
        string $proposed_date_type,
        string $confidence,
        bool $is_ambiguous
    ) {
        $this->suggestion_kind = $suggestion_kind;
        $this->normalized_date_value = $normalized_date_value;
        $this->proposed_date_type = $proposed_date_type;
        $this->confidence = $confidence;
        $this->is_ambiguous = $is_ambiguous;
    }

    /**
     * Converts the verified contract object into a standard array payload.
     *
     * @return array{suggestion_kind: string, normalized_date_value: string, proposed_date_type: string, confidence: string, is_ambiguous: bool}
     */
    public function toPayloadArray(): array
    {
        return [
            'suggestion_kind'       => $this->suggestion_kind,
            'normalized_date_value' => $this->normalized_date_value,
            'proposed_date_type'    => $this->proposed_date_type,
            'confidence'            => $this->confidence,
            'is_ambiguous'          => $this->is_ambiguous,
        ];
    }
}
