<?php

declare(strict_types=1);

namespace App\Support\Curation;

final class CurationChecklist
{
    /** @return array<string, array{stage: string, label: string, automatic: bool, allows_na: bool, help: string}> */
    public static function items(): array
    {
        $rows = [
            ['data_received', 'Intake', 'Data received', false, true],
            ['data_description_received', 'Intake', 'Data description received', false, true],
            ['metadata_complete', 'Intake', 'Metadata complete', true, false],
            ['metadata_curated', 'Curation', 'Metadata curated', false, false],
            ['data_description_finalised', 'Curation', 'Data description finalised', false, true],
            ['data_checked_and_stored', 'Curation', 'Data checked and stored on the data server', false, true],
            ['landing_page_configured', 'Curation', 'Landing page configured', true, false],
            ['doi_assigned', 'Curation', 'DOI assigned', true, false],
            ['review_link_sent', 'Review', 'Review link sent', true, false],
            ['authors_approved', 'Review', 'Approval by the authors received', false, false],
            ['related_article_doi_available', 'Publication', 'Related article DOI available', false, true],
            ['doi_registered_and_published', 'Publication', 'DOI registered and landing page published', true, false],
            ['authors_informed', 'Publication', 'Authors informed about the publication', false, true],
        ];
        $items = [];
        foreach ($rows as [$key, $stage, $label, $automatic, $allowsNa]) {
            $items[$key] = [
                'stage' => $stage, 'label' => $label, 'automatic' => $automatic, 'allows_na' => $allowsNa,
                'help' => match ($key) {
                    'review_link_sent' => 'At least one review email was queued for delivery. This does not confirm delivery. Resending does not reset author approval.',
                    'doi_registered_and_published' => 'Requires evidence from DataCite for this DOI and a published landing page. Embargo alone does not complete this step.',
                    'metadata_complete' => 'ERNIE checks its mandatory metadata fields using the saved resource.',
                    default => $automatic ? 'Determined automatically by ERNIE from the saved resource.' : ($allowsNa ? 'Mark done, or explain why this step is not applicable.' : 'Mark done after completing this step.'),
                },
            ];
        }

        return $items;
    }
}
