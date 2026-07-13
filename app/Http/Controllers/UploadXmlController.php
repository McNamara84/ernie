<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\UploadErrorCode;
use App\Exceptions\DuplicateUploadedResourceDoiException;
use App\Http\Requests\UploadXmlRequest;
use App\Services\ResourceStorageService;
use App\Services\UploadLogService;
use App\Services\Uploads\UploadedResourceDraftService;
use App\Services\Xml\DataCiteXmlImportParser;
use App\Support\UploadError;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Saloon\XmlWrangler\Exceptions\XmlReaderException;
use Saloon\XmlWrangler\XmlReader;
use VeeWee\Xml\Exception\RuntimeException as XmlRuntimeException;

class UploadXmlController extends Controller
{
    public function __construct(
        private readonly UploadLogService $uploadLogService,
        private readonly DataCiteXmlImportParser $importParser,
        private readonly UploadedResourceDraftService $uploadedResourceDraftService,
        private readonly ResourceStorageService $resourceStorageService,
    ) {}

    public function __invoke(UploadXmlRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $filename = $validated['file']->getClientOriginalName();

        $contents = $validated['file']->get();

        if ($contents === false) {
            $error = UploadError::fromCode(UploadErrorCode::FILE_UNREADABLE);
            $this->uploadLogService->logFailure('xml', $filename, $error);

            return $this->errorResponse(
                UploadErrorCode::FILE_UNREADABLE,
                $filename,
            );
        }

        try {
            $reader = XmlReader::fromString($contents);
            $result = $this->importParser->parse($reader, $filename, $contents);
        } catch (XmlReaderException|XmlRuntimeException $e) {
            $error = UploadError::withMessage(
                UploadErrorCode::XML_PARSE_ERROR,
                'The XML file could not be parsed: '.$e->getMessage()
            );
            $this->uploadLogService->logFailure('xml', $filename, $error, [
                'exception' => $e->getMessage(),
            ]);

            return $this->errorResponse(
                UploadErrorCode::XML_PARSE_ERROR,
                $filename,
                'The XML file could not be parsed: '.$e->getMessage()
            );
        } catch (\Throwable $e) {
            $error = UploadError::withMessage(
                UploadErrorCode::UNEXPECTED_ERROR,
                'An unexpected error occurred while parsing the XML file.'
            );
            $this->uploadLogService->logFailure('xml', $filename, $error, [
                'exception' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return $this->errorResponse(
                UploadErrorCode::UNEXPECTED_ERROR,
                $filename,
                'An unexpected error occurred while parsing the XML file.'
            );
        }

        $sessionPayload = $result->toSessionPayload();

        try {
            $resource = $this->uploadedResourceDraftService->storeFromPayload(
                $sessionPayload,
                $filename,
                $request->user()?->id,
            );
        } catch (DuplicateUploadedResourceDoiException $e) {
            $error = UploadError::withMessage(
                UploadErrorCode::DUPLICATE_DOI,
                'The uploaded DOI already exists on resource #'.$e->resourceId.'.'
            );
            $this->uploadLogService->logFailure('xml', $filename, $error, [
                'doi' => $e->doi,
                'resource_id' => $e->resourceId,
            ]);

            return $this->duplicateDoiResponse($filename, $e->doi, $e->resourceId);
        } catch (ValidationException $e) {
            $error = UploadError::withMessage(
                UploadErrorCode::STORAGE_ERROR,
                'The uploaded metadata could not be saved as a draft.'
            );
            $this->uploadLogService->logFailure('xml', $filename, $error, [
                'errors' => $e->errors(),
            ]);

            return $this->errorResponse(
                UploadErrorCode::STORAGE_ERROR,
                $filename,
                'The uploaded metadata could not be saved as a draft.',
            );
        } catch (\Throwable $e) {
            $error = UploadError::withMessage(
                UploadErrorCode::STORAGE_ERROR,
                'The uploaded metadata could not be saved as a draft.'
            );
            $this->uploadLogService->logFailure('xml', $filename, $error, [
                'exception' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return $this->errorResponse(
                UploadErrorCode::STORAGE_ERROR,
                $filename,
                'The uploaded metadata could not be saved as a draft.',
                500,
            );
        }

        $this->resourceStorageService->ensureSystemDate($resource, 'Accepted');

        // Keep a short-lived session fallback for older editor links and tests.
        $sessionKey = 'xml_upload_'.Str::random(32);
        session()->put($sessionKey, $sessionPayload);

        return response()->json([
            'success' => true,
            'resourceId' => $resource->id,
            'sessionKey' => $sessionKey,
        ]);
    }

    private function duplicateDoiResponse(string $filename, string $doi, int $resourceId): JsonResponse
    {
        $message = 'The uploaded DOI already exists on resource #'.$resourceId.'.';

        return response()->json([
            'success' => false,
            'message' => $message,
            'filename' => $filename,
            'error' => [
                'category' => UploadErrorCode::DUPLICATE_DOI->category(),
                'code' => UploadErrorCode::DUPLICATE_DOI->value,
                'message' => $message,
                'field' => 'doi',
                'row' => null,
                'identifier' => $doi,
                'resourceId' => $resourceId,
            ],
        ], 409);
    }

    /**
     * Create a structured error JSON response.
     */
    private function errorResponse(
        UploadErrorCode $code,
        string $filename,
        ?string $customMessage = null,
        int $status = 422
    ): JsonResponse {
        $message = $customMessage ?? $code->message();

        return response()->json([
            'success' => false,
            'message' => $message,
            'filename' => $filename,
            'error' => [
                'category' => $code->category(),
                'code' => $code->value,
                'message' => $message,
                'field' => null,
                'row' => null,
                'identifier' => null,
            ],
        ], $status);
    }
}
