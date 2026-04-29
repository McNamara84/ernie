<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Exceptions\JsonValidationException;
use App\Http\Requests\Resource\ExportResourceRequest;
use App\Models\Resource;
use App\Services\DataCiteJsonExporter;
use App\Services\DataCiteLinkedDataExporter;
use App\Services\DataCiteXmlExporter;
use App\Services\DataCiteXmlValidator;
use App\Services\JsonSchemaValidator;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

class ResourceExportController extends Controller
{
    /**
     * Export a resource as DataCite JSON.
     */
    public function exportDataCiteJson(
        ExportResourceRequest $request,
        Resource $resource,
        JsonSchemaValidator $validator,
    ): SymfonyResponse {
        $exporter = new DataCiteJsonExporter;
        $dataCiteJson = $exporter->export($resource);

        // Validate attributes against DataCite 4.7 schema (export wraps them in data.attributes).
        try {
            $validator->validate($dataCiteJson['data']['attributes']);
        } catch (JsonValidationException $e) {
            return response()->json([
                'message' => 'JSON export validation failed against DataCite Schema.',
                'errors' => $e->getErrors(),
                'schema_version' => $e->getSchemaVersion(),
            ], 422);
        }

        $timestamp = now()->format('YmdHis');
        $filename = "resource-{$resource->id}-{$timestamp}-datacite.json";

        return response()->json($dataCiteJson, 200, [
            'Content-Type' => 'application/json',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /**
     * Export a resource as DataCite XML.
     */
    public function exportDataCiteXml(ExportResourceRequest $request, Resource $resource): SymfonyResponse
    {
        try {
            $exporter = new DataCiteXmlExporter;
            $xml = $exporter->export($resource);

            $validator = new DataCiteXmlValidator;
            $isValid = $validator->validate($xml);

            $timestamp = now()->format('YmdHis');
            $filename = "resource-{$resource->id}-{$timestamp}-datacite.xml";

            $headers = [
                'Content-Type' => 'application/xml',
                'Content-Disposition' => "attachment; filename=\"{$filename}\"",
            ];

            if (! $isValid && $validator->hasWarnings()) {
                $warningMessage = $validator->getFormattedWarningMessage();
                if ($warningMessage) {
                    $headers['X-Validation-Warning'] = base64_encode($warningMessage);
                }
            }

            return response($xml, 200, $headers);
        } catch (\Exception $e) {
            Log::error('DataCite XML export failed', [
                'resource_id' => $resource->id,
                'exception' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            $message = config('app.debug')
                ? $e->getMessage()
                : 'An error occurred while generating the XML export. Please contact support if the problem persists.';

            return response()->json([
                'error' => 'Failed to export DataCite XML',
                'message' => $message,
            ], 500);
        }
    }

    /**
     * Export a resource as DataCite Linked Data JSON-LD.
     */
    public function exportJsonLd(ExportResourceRequest $request, Resource $resource): SymfonyResponse
    {
        $exporter = new DataCiteLinkedDataExporter;
        $jsonLd = $exporter->export($resource);

        $timestamp = now()->format('YmdHis');
        $filename = "resource-{$resource->id}-{$timestamp}-datacite-ld.jsonld";

        return response()->json($jsonLd, 200, [
            'Content-Type' => 'application/ld+json',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
