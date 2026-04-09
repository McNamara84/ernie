<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\IgsnMetadata;
use App\Models\Resource;

/**
 * Transforms DataCite API IGSN records into Resource + IgsnMetadata models.
 *
 * Delegates standard DataCite field mapping to DataCiteToResourceTransformer
 * and adds an IgsnMetadata record with upload_status='registered'.
 * IGSN-specific fields are left null and populated during the enrichment phase.
 */
class DataCiteToIgsnTransformer
{
    public function __construct(
        private DataCiteToResourceTransformer $baseTransformer,
    ) {}

    /**
     * Transform a DataCite IGSN record into a Resource with IgsnMetadata.
     *
     * @param  array<string, mixed>  $doiData  The DOI record from DataCite API
     * @param  int  $userId  The user ID to set as created_by
     */
    public function transform(array $doiData, int $userId): Resource
    {
        // Create the Resource with all standard DataCite fields
        $resource = $this->baseTransformer->transform($doiData, $userId);

        // Create the IGSN metadata stub (fields populated during enrichment phase)
        IgsnMetadata::create([
            'resource_id' => $resource->id,
            'upload_status' => IgsnMetadata::STATUS_REGISTERED,
        ]);

        return $resource;
    }
}
