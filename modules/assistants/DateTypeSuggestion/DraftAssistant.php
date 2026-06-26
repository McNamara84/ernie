<?php

declare(strict_types=1);

namespace Modules\Assistants\DateTypeSuggestion;

use App\Models\Resource;
use App\Services\DateType\DateTypeSchemaorgExtraction;

class DateTypeDiscoveryService
{
    public function __construct(
        private readonly DateTypeSchemaorgExtraction $schemaOrgExtraction,
    ) {
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function lookupSchemaOrgDates(Resource $resource): array
    {
        $doi = trim((string) $resource->doi);

        if ($doi === '') {
            return [];
        }

        return $this->schemaOrgExtraction->loadAllowedSchemaorg($doi);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function discoverForResource(Resource $resource): array
    {
        return $this->lookupSchemaOrgDates($resource);
    }
}