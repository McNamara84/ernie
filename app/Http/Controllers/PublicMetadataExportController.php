<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\LandingPage;
use App\Models\Resource;
use App\Services\DataCiteJsonExporter;
use App\Services\DataCiteLinkedDataExporter;
use App\Services\DataCiteXmlExporter;
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
    public function dataCiteXml(string $doiPrefix, string $slug): Response
    {
        [$resource] = $this->resolvePublishedResource($doiPrefix, $slug);
        $xml = app(DataCiteXmlExporter::class)->export($resource);

        return $this->xmlDownload($xml, "{$slug}-datacite.xml");
    }

    public function dataCiteJson(string $doiPrefix, string $slug): JsonResponse
    {
        [$resource] = $this->resolvePublishedResource($doiPrefix, $slug);
        $json = app(DataCiteJsonExporter::class)->export($resource);

        return response()->json($json, Response::HTTP_OK, [
            'Content-Type' => 'application/json; charset=UTF-8',
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

    private function xmlDownload(string $xml, string $filename): Response
    {
        return response($xml, Response::HTTP_OK, [
            'Content-Type' => 'application/xml; charset=UTF-8',
            'Content-Disposition' => $this->attachment($filename),
        ]);
    }

    private function attachment(string $filename): string
    {
        $safe = preg_replace('/[^a-zA-Z0-9._-]/', '-', $filename) ?: 'metadata';

        return "attachment; filename=\"{$safe}\"";
    }
}
