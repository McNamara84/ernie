<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\Log;

/**
 * Validates IGSN CSV file uploads.
 */
class UploadIgsnCsvRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'file' => [
                'required',
                'file',
                'mimes:csv,txt',
                'max:10240', // 10 MB
            ],
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
            'file.required' => 'Please upload a CSV file.',
            'file.file' => 'The uploaded item must be a file.',
            'file.mimes' => 'The file must be a CSV file.',
            'file.max' => 'The file must not be larger than 10 MB.',
        ];
    }

    /**
     * Handle a failed validation attempt.
     *
     * Logs the validation failure and returns a structured JSON error response.
     *
     * DESIGN NOTE: The response intentionally includes BOTH formats:
     *
     * 1. Laravel standard format (`message` + `errors`):
     *    - `message`: First validation error message
     *    - `errors`: Array of validation messages keyed by field name
     *    - Purpose: Enables use of Laravel's `assertJsonValidationErrors()` in tests
     *
     * 2. Custom structured format (`error`):
     *    - `error.category`: Error category (validation, data, server)
     *    - `error.code`: Machine-readable error code for frontend handling
     *    - `error.message`: Human-readable error message
     *    - Purpose: Enables enhanced error display with categorization and icons
     *
     * Frontend consumers should use the `error` field for display purposes.
     * The `errors` field exists primarily for backward compatibility and testing.
     */
    protected function failedValidation(Validator $validator): void
    {
        $errors = $validator->errors()->toArray();
        $filename = $this->file('file')?->getClientOriginalName() ?? 'unknown';

        Log::info('IGSN CSV upload validation failed', [
            'upload_type' => 'csv',
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
                // Check for required/upload BEFORE csv/type to avoid false positives
                if (str_contains($lowerError, 'required') || str_contains($lowerError, 'upload')) {
                    return 'file_required';
                }
                if (str_contains($lowerError, 'csv') || str_contains($lowerError, 'type')) {
                    return 'invalid_file_type';
                }
            }
        }

        return 'validation_error';
    }
}
