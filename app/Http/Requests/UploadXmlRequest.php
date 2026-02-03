<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\Log;

class UploadXmlRequest extends FormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'file' => ['required', 'file', 'mimes:xml', 'max:4096'],
        ];
    }

    /**
     * Get custom messages for validator errors.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'file.required' => 'Please upload an XML file.',
            'file.file' => 'The uploaded item must be a file.',
            'file.mimes' => 'The file must be a valid XML file.',
            'file.max' => 'The file must not be larger than 4 MB.',
        ];
    }

    /**
     * Handle a failed validation attempt.
     *
     * Logs the validation failure and returns a structured JSON error response.
     * The response format is compatible with Laravel's standard validation format
     * (contains 'message' and 'errors' keys) while also including our custom fields.
     */
    protected function failedValidation(Validator $validator): void
    {
        $errors = $validator->errors()->toArray();
        $filename = $this->file('file')?->getClientOriginalName() ?? 'unknown';

        Log::info('XML upload validation failed', [
            'upload_type' => 'xml',
            'filename' => $filename,
            'errors' => $errors,
            'user_id' => $this->user()?->id,
            'user_email' => $this->user()?->email,
            'ip_address' => $this->ip(),
        ]);

        // Get the first error message for the summary
        $firstError = collect($errors)->flatten()->first() ?? 'Validation failed';

        // Map Laravel validation to our error codes
        $errorCode = $this->mapValidationErrorCode($errors);

        // Response format is compatible with Laravel's standard validation format
        // (message + errors) while also including our structured error fields
        throw new HttpResponseException(response()->json([
            // Standard Laravel validation format (for assertJsonValidationErrors)
            'message' => $firstError,
            'errors' => $errors,
            // Our custom structured error format
            'success' => false,
            'filename' => $filename,
            'error' => [
                'category' => 'validation',
                'code' => $errorCode,
                'message' => $firstError,
                'field' => 'file',
                'row' => null,
                'identifier' => null,
            ],
        ], 422));
    }

    /**
     * Map Laravel validation error keys to our error codes.
     *
     * @param  array<string, array<int, string>>  $errors
     */
    private function mapValidationErrorCode(array $errors): string
    {
        if (isset($errors['file'])) {
            $fileErrors = $errors['file'];
            foreach ($fileErrors as $error) {
                $lowerError = strtolower($error);
                if (str_contains($lowerError, 'larger') || str_contains($lowerError, 'size')) {
                    return 'file_too_large';
                }
                // Check for required/upload BEFORE xml/type to avoid false positives
                // ("Please upload an XML file" contains both "upload" and "xml")
                if (str_contains($lowerError, 'required') || str_contains($lowerError, 'upload')) {
                    return 'file_required';
                }
                if (str_contains($lowerError, 'xml') || str_contains($lowerError, 'type')) {
                    return 'invalid_file_type';
                }
            }
        }

        return 'validation_error';
    }
}
