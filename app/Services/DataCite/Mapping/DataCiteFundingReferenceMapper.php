<?php

declare(strict_types=1);

namespace App\Services\DataCite\Mapping;

use App\Models\FundingReference;

final readonly class DataCiteFundingReferenceMapper
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(FundingReference $funding): array
    {
        $data = [
            'funderName' => $funding->funder_name,
        ];

        if ($funding->funder_identifier) {
            $data['funderIdentifier'] = $funding->funder_identifier;
            $data['funderIdentifierType'] = $funding->funderIdentifierType->name ?? 'Other';

            if ($funding->scheme_uri) {
                $data['schemeUri'] = $funding->scheme_uri;
            }
        }

        if ($funding->award_number) {
            $data['awardNumber'] = $funding->award_number;
        }

        if ($funding->award_uri) {
            $data['awardUri'] = $funding->award_uri;
        }

        if ($funding->award_title) {
            $data['awardTitle'] = $funding->award_title;
        }

        return $data;
    }
}