<?php

declare(strict_types=1);

namespace App\Services\Imports\Subjects;

/**
 * Canonical result shared by DataCite XML and JSON subject imports.
 */
final readonly class SubjectImportResult
{
    /**
     * @param  list<string>  $freeKeywords
     * @param  list<array<string, mixed>>  $controlledKeywords
     */
    public function __construct(
        public array $freeKeywords,
        public array $controlledKeywords,
    ) {}

    /**
     * Compatibility view for the old upload-session payload.
     *
     * MSL historically had a dedicated payload key while also being accepted by
     * the editor's general controlled-keyword list. GEMET had its own editor key.
     * The canonical list remains the only list used for persistence.
     *
     * @return array{
     *     gcmdKeywords: list<array<string, mixed>>,
     *     mslKeywords: list<array<string, mixed>>,
     *     gemetKeywords: list<array<string, mixed>>
     * }
     */
    public function legacyKeywordPayload(): array
    {
        $gemet = [];
        $msl = [];
        $general = [];

        foreach ($this->controlledKeywords as $keyword) {
            $scheme = (string) ($keyword['scheme'] ?? '');

            if ($scheme === SubjectImportNormalizer::SCHEME_GEMET) {
                $gemet[] = $keyword;

                continue;
            }

            $general[] = $keyword;

            if ($scheme === SubjectImportNormalizer::SCHEME_MSL) {
                $msl[] = $keyword;
            }
        }

        return [
            'gcmdKeywords' => $general,
            'mslKeywords' => $msl,
            'gemetKeywords' => $gemet,
        ];
    }
}
