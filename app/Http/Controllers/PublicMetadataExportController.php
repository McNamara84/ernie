<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\LandingPage;
use App\Models\Resource;
use App\Services\DataCiteJsonExporter;
use App\Services\DataCiteLinkedDataExporter;
use App\Services\DataCiteXmlExporter;
use App\Services\Igsn\Export\IgsnCoreMetadataException;
use App\Services\Igsn\Export\IgsnCoreXmlExporter;
use App\Services\Igsn\Export\IgsnCoreXmlValidator;
use App\Services\Iso19115\Iso19115JsonExporter;
use App\Services\Iso19115\Iso19115JsonValidator;
use App\Services\Iso19115\Iso19115ResourceProfileService;
use App\Services\Iso19115\Iso19115XmlExporter;
use App\Services\Iso19115\Iso19115XmlValidator;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Public, canonical metadata representations for published landing pages.
 */
class PublicMetadataExportController extends Controller
{
    public function igsnCore(string $doiPrefix, string $slug, IgsnCoreXmlExporter $exporter, IgsnCoreXmlValidator $validator): Response
    {
        [$resource] = $this->resolvePublishedResource($doiPrefix, $slug);
        abort_unless($resource->isIgsn(), Response::HTTP_NOT_FOUND, 'Metadata representation not found');

        try {
            $result = $exporter->export($resource);
            $validation = $validator->validate($result->xml);
        } catch (IgsnCoreMetadataException $exception) {
            return response()->json([
                'code' => 'igsn_core_metadata_incomplete',
                'message' => 'This sample cannot be exported as IGSN Core yet. Required metadata is missing or unsupported.',
                'fields' => $exception->fields,
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        } catch (\Throwable $exception) {
            Log::error('IGSN Core export failed', ['resource_id' => $resource->id, 'exception_type' => $exception::class]);

            return $this->igsnCoreFailure();
        }
        if (! $validation->isValid()) {
            Log::error('IGSN Core XML failed local validation', ['resource_id' => $resource->id, 'error_count' => count($validation->errors), 'snapshot' => config('igsn_core.snapshot')]);

            return $this->igsnCoreFailure();
        }
        if ($result->omissions !== []) {
            Log::notice('IGSN Core export omitted unsupported optional metadata', ['resource_id' => $resource->id, 'codes' => $result->omissions]);
        }

        return $this->xmlDownload($result->xml, "{$slug}-igsn-core-2.0.xml");
    }

    private function igsnCoreFailure(): JsonResponse
    {
        return response()->json([
            'code' => 'igsn_core_export_failed',
            'message' => 'A valid IGSN Core metadata file could not be generated. Please try again later.',
        ], Response::HTTP_INTERNAL_SERVER_ERROR);
    }

    public function dataCiteXml(string $doiPrefix, string $slug): Response
    {
        [$resource] = $this->resolvePublishedResource($doiPrefix, $slug);
        $xml = app(DataCiteXmlExporter::class)->export($resource);

        return $this->xmlDownload(
            $xml,
            "{$slug}-datacite.xml",
            'application/vnd.datacite.datacite+xml',
        );
    }

    public function dataCiteJson(string $doiPrefix, string $slug): JsonResponse
    {
        [$resource] = $this->resolvePublishedResource($doiPrefix, $slug);
        $json = app(DataCiteJsonExporter::class)->export($resource);

        return response()->json($json, Response::HTTP_OK, [
            'Content-Type' => 'application/vnd.datacite.datacite+json; charset=UTF-8',
            'Content-Disposition' => $this->attachment("{$slug}-datacite.json"),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    public function dataCiteJsonLd(string $doiPrefix, string $slug): JsonResponse
    {
        [$resource] = $this->resolvePublishedResource($doiPrefix, $slug);
        $jsonLd = app(DataCiteLinkedDataExporter::class)->export($resource);

        return response()->json($jsonLd, Response::HTTP_OK, [
            'Content-Type' => 'application/ld+json; charset=UTF-8',
            'Content-Disposition' => $this->attachment("{$slug}-datacite.jsonld"),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    public function iso19115(
        string $doiPrefix,
        string $slug,
        Iso19115ResourceProfileService $profile,
        Iso19115XmlExporter $exporter,
        Iso19115XmlValidator $validator,
    ): Response {
        [$resource] = $this->resolvePublishedResource($doiPrefix, $slug);
        abort_unless($profile->supports($resource), Response::HTTP_NOT_FOUND, 'Metadata representation not found');

        $xml = $exporter->export($resource);
        $validation = $validator->validate($xml);
        if (! $validation->isValid()) {
            Log::error('Generated ISO 19115-3 XML failed local validation', [
                'resource_id' => $resource->id,
                'errors' => $validation->errors,
            ]);
            abort(Response::HTTP_INTERNAL_SERVER_ERROR, 'Failed to generate a valid metadata representation');
        }

        $headers = [
            'Content-Type' => (string) config('iso19115.media_type'),
            'Content-Disposition' => $this->attachment("{$slug}-iso-19115-3.xml"),
        ];
        if ($validation->warnings !== []) {
            $headers['X-ISO19115-Validation-Warnings'] = base64_encode(implode("\n", $validation->warnings));
        }

        return response($xml, Response::HTTP_OK, $headers);
    }

    public function iso19115Json(
        string $doiPrefix,
        string $slug,
        Iso19115ResourceProfileService $profile,
        Iso19115JsonExporter $exporter,
        Iso19115JsonValidator $validator,
    ): JsonResponse {
        [$resource] = $this->resolvePublishedResource($doiPrefix, $slug);
        abort_unless($profile->supportsJson($resource), Response::HTTP_NOT_FOUND, 'Metadata representation not found');

        try {
            $json = $exporter->export($resource);
            $validation = $validator->validate($json);
        } catch (\Throwable $exception) {
            Log::error('Failed to generate ISO 19115-4 JSON', [
                'resource_id' => $resource->id,
                'exception_type' => $exception::class,
            ]);
            abort(Response::HTTP_INTERNAL_SERVER_ERROR, 'Failed to generate a valid metadata representation');
        }

        if (! $validation->isValid()) {
            Log::error('Generated ISO 19115-4 JSON failed local validation', [
                'resource_id' => $resource->id,
                'errors' => $validation->errors,
            ]);
            abort(Response::HTTP_INTERNAL_SERVER_ERROR, 'Failed to generate a valid metadata representation');
        }

        return response()->json($json, Response::HTTP_OK, [
            'Content-Type' => 'application/json; charset=UTF-8',
            'Content-Disposition' => $this->attachment("{$slug}-iso-19115-4.json"),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    /**
     * @return array{0: Resource, 1: LandingPage}
     */
    private function resolvePublishedResource(string $doiPrefix, string $slug): array
    {
        $landingPage = LandingPage::query()
            ->where('doi_prefix', $doiPrefix)
            ->where('slug', $slug)
            ->where('is_published', true)
            ->first();

        abort_if($landingPage === null, Response::HTTP_NOT_FOUND, 'Landing page not found');

        $resource = Resource::query()->find($landingPage->resource_id);
        abort_if($resource === null, Response::HTTP_NOT_FOUND, 'Resource not found');

        return [$resource, $landingPage];
    }

    private function xmlDownload(string $xml, string $filename, string $mediaType = 'application/xml'): Response
    {
        return response($xml, Response::HTTP_OK, [
            'Content-Type' => $mediaType.'; charset=UTF-8',
            'Content-Disposition' => $this->attachment($filename),
        ]);
    }

    private function attachment(string $filename): string
    {
        $safe = preg_replace('/[^a-zA-Z0-9._-]/', '-', $filename) ?: 'metadata';

        return "attachment; filename=\"{$safe}\"";
    }
}
