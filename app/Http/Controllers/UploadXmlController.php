<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\UploadErrorCode;
use App\Http\Requests\UploadXmlRequest;
use App\Services\UploadLogService;
use App\Services\Xml\DataCiteXmlImportParser;
use App\Support\UploadError;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Str;
use Saloon\XmlWrangler\Exceptions\XmlReaderException;
use Saloon\XmlWrangler\XmlReader;
use VeeWee\Xml\Exception\RuntimeException as XmlRuntimeException;

class UploadXmlController extends Controller
{
    public function __construct(
        private readonly UploadLogService $uploadLogService,
        private readonly DataCiteXmlImportParser $importParser,
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
            $result = $this->importParser->parse($reader, $filename);
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

        // Store data in session to avoid 414 URI Too Long errors
        $sessionKey = 'xml_upload_'.Str::random(32);
        session()->put($sessionKey, $result->toSessionPayload());

        return response()->json([
            'sessionKey' => $sessionKey,
        ]);
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
