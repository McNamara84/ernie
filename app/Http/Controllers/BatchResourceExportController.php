<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Resource;
use App\Services\DataCiteJsonExporter;
use App\Services\DataCiteLinkedDataExporter;
use App\Services\DataCiteXmlExporter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use ZipArchive;

/**
 * Controller for exporting multiple resources as a ZIP archive.
 *
 * Supports three DataCite formats: JSON (4.7), XML (4.7) and JSON-LD.
 * The archive streams back to the browser as a download.
 */
class BatchResourceExportController extends Controller
{
    /**
     * Maximum number of resources that can be exported in a single batch.
     */
    private const MAX_BATCH_SIZE = 100;

    private const FORMAT_JSON = 'datacite-json';

    private const FORMAT_XML = 'datacite-xml';

    private const FORMAT_JSONLD = 'jsonld';

    /**
     * Export the selected resources as a downloadable ZIP archive.
     */
    public function export(Request $request): BinaryFileResponse
    {
        $validated = $request->validate([
            'ids' => ['required', 'array', 'min:1', 'max:' . self::MAX_BATCH_SIZE],
            'ids.*' => ['required', 'integer', 'exists:resources,id'],
            'format' => ['required', 'string', 'in:' . self::FORMAT_JSON . ',' . self::FORMAT_XML . ',' . self::FORMAT_JSONLD],
        ]);

        /** @var array<int> $ids */
        $ids = array_values(array_unique($validated['ids']));

        /** @var string $format */
        $format = $validated['format'];

        $resources = Resource::with([
            'igsnMetadata',
            'landingPage',
            'resourceType',
            'language',
            'publisher',
            'titles.titleType',
            'creators.creatorable',
            'creators.affiliations',
            'contributors.contributorable',
            'contributors.contributorTypes',
            'contributors.affiliations',
            'descriptions.descriptionType',
            'dates.dateType',
            'subjects',
            'geoLocations',
            'rights',
            'relatedIdentifiers.identifierType',
            'relatedIdentifiers.relationType',
            'fundingReferences.funderIdentifierType',
            'alternateIdentifiers',
            'sizes',
            'formats',
        ])
            ->whereIn('id', $ids)
            ->get();

        abort_if($resources->isEmpty(), 404, 'No resources found for the given ids.');

        $tmpPath = tempnam(sys_get_temp_dir(), 'ernie-export-');

        if ($tmpPath === false) {
            abort(500, 'Unable to create temporary file for ZIP archive.');
        }

        $zip = new ZipArchive;

        if ($zip->open($tmpPath, ZipArchive::OVERWRITE) !== true) {
            @unlink($tmpPath);
            abort(500, 'Unable to open temporary ZIP archive for writing.');
        }

        $jsonExporter = new DataCiteJsonExporter;
        $xmlExporter = new DataCiteXmlExporter;
        $jsonLdExporter = new DataCiteLinkedDataExporter;

        $extension = match ($format) {
            self::FORMAT_XML => 'xml',
            self::FORMAT_JSONLD => 'jsonld',
            default => 'json',
        };

        foreach ($resources as $resource) {
            try {
                $content = match ($format) {
                    self::FORMAT_XML => $xmlExporter->export($resource),
                    self::FORMAT_JSONLD => json_encode(
                        $jsonLdExporter->export($resource),
                        JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
                    ),
                    default => json_encode(
                        $jsonExporter->export($resource),
                        JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
                    ),
                };

                if ($content === false) {
                    continue;
                }

                $zip->addFromString(
                    self::buildEntryName($resource, $extension),
                    $content,
                );
            } catch (\Throwable $e) {
                Log::error('Batch resource export: entry failed', [
                    'resource_id' => $resource->id,
                    'format' => $format,
                    'error' => $e->getMessage(),
                ]);
                // Skip this entry but keep producing the archive.
                continue;
            }
        }

        $zip->close();

        $timestamp = now()->format('Ymd-His');
        $downloadName = "resources-export-{$format}-{$timestamp}.zip";

        return response()
            ->download($tmpPath, $downloadName, [
                'Content-Type' => 'application/zip',
            ])
            ->deleteFileAfterSend(true);
    }

    /**
     * Derive a safe, unique file name for an archive entry.
     */
    private static function buildEntryName(Resource $resource, string $extension): string
    {
        $idPart = (string) $resource->id;
        $doi = $resource->doi;
        $doiPart = $doi !== null && $doi !== ''
            ? preg_replace('/[^A-Za-z0-9._-]+/', '-', $doi)
            : '';

        $base = $doiPart !== ''
            ? "resource-{$idPart}-{$doiPart}"
            : "resource-{$idPart}";

        return "{$base}.{$extension}";
    }
}
