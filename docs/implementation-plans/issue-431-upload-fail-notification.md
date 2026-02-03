# Implementation Plan: Upload Fail Notification (Issue #431)

## Overview

**Issue:** [#431 - Upload fail notification](https://github.com/McNamara84/ernie/issues/431)

**User Story:** As a data curator, I want to be informed when a data upload fails to ensure that all data has been uploaded.

**Acceptance Criteria:**
- [ ] As soon as an upload fails, a notification appears for the curator that the upload was not possible
- [ ] It lists which file could not be uploaded
- [ ] It shows why the upload failed

## Decisions Made

| # | Question | Decision | Reasoning |
|---|----------|----------|-----------|
| 1 | Which uploads affected? | Both XML and IGSN CSV | Consistent error handling across all upload features |
| 2 | Notification type? | Toast + Modal (combined) | Toast for simple errors, Modal for complex errors with multiple issues |
| 3 | Error categorization? | Categorized errors | Better UX - curators can self-fix issues |
| 4 | Localization? | English only | Consistent with current app language |
| 5 | Error logging? | Server-side logging for admins | Errors logged to `/logs` page for admin visibility |

## Current State Analysis

### XML Upload Flow
1. **Frontend:** [dashboard.tsx](../../resources/js/pages/dashboard.tsx) → `handleXmlFiles()` → fetch POST
2. **Component:** [unified-dropzone.tsx](../../resources/js/components/unified-dropzone.tsx) → `uploadXml()` method
3. **Backend:** [UploadXmlController.php](../../app/Http/Controllers/UploadXmlController.php) → parses XML, stores in session
4. **Validation:** [UploadXmlRequest.php](../../app/Http/Requests/UploadXmlRequest.php) → file, mimes:xml, max:4096

**Current Error Handling:**
- ❌ Generic error message ("Upload failed: {message}")
- ❌ No structured error response
- ❌ No server-side logging for upload failures

### IGSN CSV Upload Flow
1. **Component:** [unified-dropzone.tsx](../../resources/js/components/unified-dropzone.tsx) → `uploadCsv()` method
2. **Backend:** [UploadIgsnCsvController.php](../../app/Http/Controllers/UploadIgsnCsvController.php)
3. **Validation:** [UploadIgsnCsvRequest.php](../../app/Http/Requests/UploadIgsnCsvRequest.php) → file, mimes:csv,txt, max:10240
4. **Parsing:** `IgsnCsvParserService` → validates rows, checks duplicates

**Current Error Handling:**
- ✅ Row-level errors returned (`row`, `igsn`, `message`)
- ✅ Displayed in scrollable list
- ❌ No toast notification for immediate feedback
- ❌ Server-side logging only for complete failures, not partial

## Error Categories

### 1. Validation Errors (Client/Server)
| Error Type | XML | CSV | HTTP Status |
|------------|-----|-----|-------------|
| File too large | ✓ | ✓ | 422 |
| Wrong file type | ✓ | ✓ | 422 |
| File unreadable | ✓ | ✓ | 422 |
| Missing required fields | ✓ | ✓ | 422 |
| Invalid format/structure | ✓ | ✓ | 422 |

### 2. Data Errors (Server)
| Error Type | XML | CSV | HTTP Status |
|------------|-----|-----|-------------|
| Duplicate DOI/IGSN | ✓ | ✓ | 422 |
| Invalid DOI format | ✓ | - | 422 |
| Parent IGSN not found | - | ✓ | 422 |
| XML parsing error | ✓ | - | 422 |
| CSV parsing error | - | ✓ | 422 |

### 3. Server Errors
| Error Type | HTTP Status |
|------------|-------------|
| Database error | 500 |
| Session error | 500 |
| Unexpected exception | 500 |

### 4. Client Errors
| Error Type | Handling |
|------------|----------|
| CSRF token expired | 419 → auto-reload |
| Network error | Toast with retry |

## Implementation Tasks

### Phase 1: Backend - Structured Error Responses

#### Task 1.1: Create UploadError DTO
Create a standardized error response structure.

**File:** `app/Support/UploadError.php`

```php
<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Standardized upload error response structure.
 */
final readonly class UploadError
{
    public function __construct(
        public string $category,    // 'validation', 'data', 'server', 'client'
        public string $code,        // 'file_too_large', 'invalid_format', etc.
        public string $message,     // Human-readable message
        public ?string $field = null,      // Field that caused error (if applicable)
        public ?int $row = null,           // Row number (CSV only)
        public ?string $identifier = null, // DOI/IGSN that caused error
    ) {}

    /**
     * @return array{category: string, code: string, message: string, field: ?string, row: ?int, identifier: ?string}
     */
    public function toArray(): array
    {
        return [
            'category' => $this->category,
            'code' => $this->code,
            'message' => $this->message,
            'field' => $this->field,
            'row' => $this->row,
            'identifier' => $this->identifier,
        ];
    }
}
```

#### Task 1.2: Create UploadErrorCode Enum
Centralized error codes for consistent error identification.

**File:** `app/Enums/UploadErrorCode.php`

```php
<?php

declare(strict_types=1);

namespace App\Enums;

enum UploadErrorCode: string
{
    // Validation errors
    case FILE_TOO_LARGE = 'file_too_large';
    case INVALID_FILE_TYPE = 'invalid_file_type';
    case FILE_UNREADABLE = 'file_unreadable';
    case MISSING_REQUIRED_FIELD = 'missing_required_field';
    
    // XML-specific errors
    case XML_PARSE_ERROR = 'xml_parse_error';
    case INVALID_XML_STRUCTURE = 'invalid_xml_structure';
    case INVALID_DOI_FORMAT = 'invalid_doi_format';
    
    // CSV-specific errors
    case CSV_PARSE_ERROR = 'csv_parse_error';
    case INVALID_CSV_STRUCTURE = 'invalid_csv_structure';
    case MISSING_HEADER = 'missing_header';
    case PARENT_NOT_FOUND = 'parent_not_found';
    
    // Data errors
    case DUPLICATE_DOI = 'duplicate_doi';
    case DUPLICATE_IGSN = 'duplicate_igsn';
    
    // Server errors
    case DATABASE_ERROR = 'database_error';
    case SESSION_ERROR = 'session_error';
    case UNEXPECTED_ERROR = 'unexpected_error';
    
    /**
     * Get human-readable message for error code.
     */
    public function message(): string
    {
        return match ($this) {
            self::FILE_TOO_LARGE => 'The file exceeds the maximum allowed size.',
            self::INVALID_FILE_TYPE => 'The file type is not supported.',
            self::FILE_UNREADABLE => 'The file could not be read.',
            self::MISSING_REQUIRED_FIELD => 'A required field is missing.',
            self::XML_PARSE_ERROR => 'The XML file could not be parsed.',
            self::INVALID_XML_STRUCTURE => 'The XML structure is invalid.',
            self::INVALID_DOI_FORMAT => 'The DOI format is invalid.',
            self::CSV_PARSE_ERROR => 'The CSV file could not be parsed.',
            self::INVALID_CSV_STRUCTURE => 'The CSV structure is invalid.',
            self::MISSING_HEADER => 'A required header is missing.',
            self::PARENT_NOT_FOUND => 'The parent IGSN could not be found.',
            self::DUPLICATE_DOI => 'This DOI already exists in the database.',
            self::DUPLICATE_IGSN => 'This IGSN already exists in the database.',
            self::DATABASE_ERROR => 'A database error occurred.',
            self::SESSION_ERROR => 'A session error occurred.',
            self::UNEXPECTED_ERROR => 'An unexpected error occurred.',
        };
    }

    /**
     * Get error category.
     */
    public function category(): string
    {
        return match ($this) {
            self::FILE_TOO_LARGE,
            self::INVALID_FILE_TYPE,
            self::FILE_UNREADABLE,
            self::MISSING_REQUIRED_FIELD => 'validation',
            
            self::XML_PARSE_ERROR,
            self::INVALID_XML_STRUCTURE,
            self::INVALID_DOI_FORMAT,
            self::CSV_PARSE_ERROR,
            self::INVALID_CSV_STRUCTURE,
            self::MISSING_HEADER,
            self::PARENT_NOT_FOUND,
            self::DUPLICATE_DOI,
            self::DUPLICATE_IGSN => 'data',
            
            self::DATABASE_ERROR,
            self::SESSION_ERROR,
            self::UNEXPECTED_ERROR => 'server',
        };
    }
}
```

#### Task 1.3: Update UploadXmlController
Add structured error responses and logging.

**File:** `app/Http/Controllers/UploadXmlController.php`

Changes:
1. Add try-catch blocks around XML parsing
2. Return structured error responses with error codes
3. Log failures to Laravel log with context

```php
// In __invoke method, wrap parsing in try-catch:
try {
    $reader = XmlReader::fromString($contents);
    // ... existing parsing code
} catch (\Saloon\XmlWrangler\Exceptions\XmlReaderException $e) {
    Log::warning('XML upload failed: parsing error', [
        'filename' => $validated['file']->getClientOriginalName(),
        'user_id' => $request->user()?->id,
        'error' => $e->getMessage(),
    ]);
    
    return response()->json([
        'success' => false,
        'error' => [
            'category' => 'data',
            'code' => 'xml_parse_error',
            'message' => 'The XML file could not be parsed: ' . $e->getMessage(),
            'filename' => $validated['file']->getClientOriginalName(),
        ],
    ], 422);
}
```

#### Task 1.4: Update UploadIgsnCsvController
Enhance error responses with categories and logging.

**File:** `app/Http/Controllers/UploadIgsnCsvController.php`

Changes:
1. Add `filename` to all error responses
2. Categorize errors by type
3. Log all failures with full context

#### Task 1.5: Update Form Request Error Messages
Improve validation error messages in both request classes.

**File:** `app/Http/Requests/UploadXmlRequest.php`

```php
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
 */
protected function failedValidation(\Illuminate\Contracts\Validation\Validator $validator): void
{
    Log::warning('XML upload validation failed', [
        'errors' => $validator->errors()->toArray(),
        'user_id' => $this->user()?->id,
    ]);
    
    throw new \Illuminate\Validation\ValidationException($validator);
}
```

### Phase 2: Frontend - Enhanced Error Display

#### Task 2.1: Create Upload Error Types
Define TypeScript types for structured errors.

**File:** `resources/js/types/upload.ts`

```typescript
export type UploadErrorCategory = 'validation' | 'data' | 'server' | 'client';

export interface UploadError {
    category: UploadErrorCategory;
    code: string;
    message: string;
    field?: string;
    row?: number;
    identifier?: string;
}

export interface UploadErrorResponse {
    success: false;
    message: string;
    filename?: string;
    error?: UploadError;
    errors?: UploadError[];
}

export interface UploadSuccessResponse {
    success: true;
    message?: string;
    created?: number;
    sessionKey?: string;
}

export type UploadResponse = UploadErrorResponse | UploadSuccessResponse;
```

#### Task 2.2: Create UploadErrorModal Component
Modal for displaying complex errors (multiple CSV row errors).

**File:** `resources/js/components/upload-error-modal.tsx`

```typescript
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Button } from '@/components/ui/button';
import { ScrollArea } from '@/components/ui/scroll-area';
import { AlertCircle, XCircle } from 'lucide-react';
import { UploadError } from '@/types/upload';

interface UploadErrorModalProps {
    open: boolean;
    onClose: () => void;
    filename: string;
    message: string;
    errors: UploadError[];
}

export function UploadErrorModal({ open, onClose, filename, message, errors }: UploadErrorModalProps) {
    // Group errors by category
    const groupedErrors = errors.reduce((acc, err) => {
        if (!acc[err.category]) acc[err.category] = [];
        acc[err.category].push(err);
        return acc;
    }, {} as Record<string, UploadError[]>);

    return (
        <Dialog open={open} onOpenChange={onClose}>
            <DialogContent className="max-w-2xl">
                <DialogHeader>
                    <DialogTitle className="flex items-center gap-2 text-destructive">
                        <AlertCircle className="h-5 w-5" />
                        Upload Failed
                    </DialogTitle>
                    <DialogDescription>
                        {filename}: {message}
                    </DialogDescription>
                </DialogHeader>

                <ScrollArea className="max-h-96">
                    {Object.entries(groupedErrors).map(([category, categoryErrors]) => (
                        <div key={category} className="mb-4">
                            <h4 className="font-medium capitalize mb-2">{category} Errors</h4>
                            <ul className="space-y-2">
                                {categoryErrors.map((err, i) => (
                                    <li key={i} className="flex items-start gap-2 text-sm">
                                        <XCircle className="h-4 w-4 mt-0.5 text-destructive shrink-0" />
                                        <span>
                                            {err.row && <strong>Row {err.row}: </strong>}
                                            {err.identifier && <code className="bg-muted px-1 rounded">{err.identifier}</code>}
                                            {' '}{err.message}
                                        </span>
                                    </li>
                                ))}
                            </ul>
                        </div>
                    ))}
                </ScrollArea>

                <DialogFooter>
                    <Button onClick={onClose}>Close</Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
```

#### Task 2.3: Update UnifiedDropzone Component
Integrate toast notifications and error modal.

**File:** `resources/js/components/unified-dropzone.tsx`

Changes:
1. Import `toast` from 'sonner'
2. Add state for error modal visibility
3. Show toast for simple errors (single error, validation)
4. Show modal for complex errors (multiple row errors)
5. Include filename in all error displays

```typescript
// Add imports
import { toast } from 'sonner';
import { UploadErrorModal } from '@/components/upload-error-modal';
import type { UploadError, UploadErrorResponse } from '@/types/upload';

// Add state
const [showErrorModal, setShowErrorModal] = useState(false);
const [modalErrors, setModalErrors] = useState<UploadError[]>([]);

// In error handling:
const handleUploadError = (response: UploadErrorResponse, filename: string) => {
    const errors = response.errors ?? (response.error ? [response.error] : []);
    
    if (errors.length <= 3) {
        // Show toast for simple errors
        toast.error(`Upload failed: ${filename}`, {
            description: response.message,
            duration: 8000,
        });
    } else {
        // Show modal for complex errors
        setModalErrors(errors);
        setShowErrorModal(true);
    }
    
    setUploadState('error');
    setError(response.message);
};
```

#### Task 2.4: Update dashboard.tsx
Add toast notification for XML upload errors.

**File:** `resources/js/pages/dashboard.tsx`

```typescript
import { toast } from 'sonner';

// In handleXmlFiles catch block:
catch (error) {
    console.error('XML upload failed', error);
    const message = error instanceof Error ? error.message : 'Upload failed';
    
    toast.error('XML Upload Failed', {
        description: message,
        duration: 8000,
    });
    
    throw error;
}
```

### Phase 3: Server-Side Logging

#### Task 3.1: Create UploadLogService
Dedicated service for upload failure logging with structured data.

**File:** `app/Services/UploadLogService.php`

```php
<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\UploadErrorCode;
use Illuminate\Support\Facades\Log;

/**
 * Service for logging upload failures with structured data.
 */
class UploadLogService
{
    /**
     * Log an upload failure.
     *
     * @param string $uploadType 'xml' or 'csv'
     * @param string $filename
     * @param UploadErrorCode $errorCode
     * @param string $message
     * @param array<string, mixed> $context
     */
    public function logFailure(
        string $uploadType,
        string $filename,
        UploadErrorCode $errorCode,
        string $message,
        array $context = []
    ): void {
        $logLevel = match ($errorCode->category()) {
            'validation' => 'info',      // User error, not critical
            'data' => 'warning',         // Data issue, may need attention
            'server' => 'error',         // Server error, needs investigation
            default => 'warning',
        };

        Log::$logLevel("Upload failed: {$uploadType}", [
            'upload_type' => $uploadType,
            'filename' => $filename,
            'error_code' => $errorCode->value,
            'error_category' => $errorCode->category(),
            'message' => $message,
            'user_id' => auth()->id(),
            'user_email' => auth()->user()?->email,
            'timestamp' => now()->toIso8601String(),
            ...$context,
        ]);
    }

    /**
     * Log multiple row errors (for CSV uploads).
     *
     * @param string $filename
     * @param list<array{row: int, igsn: string, message: string}> $errors
     */
    public function logCsvRowErrors(string $filename, array $errors): void
    {
        Log::warning('IGSN CSV upload had row errors', [
            'upload_type' => 'csv',
            'filename' => $filename,
            'error_count' => count($errors),
            'errors' => array_slice($errors, 0, 10), // Log first 10 errors
            'user_id' => auth()->id(),
            'user_email' => auth()->user()?->email,
            'timestamp' => now()->toIso8601String(),
        ]);
    }
}
```

#### Task 3.2: Integrate Logging in Controllers
Inject and use `UploadLogService` in both upload controllers.

### Phase 4: Testing

#### Task 4.1: Backend Tests

**File:** `tests/pest/Feature/UploadXmlControllerTest.php`

```php
it('returns structured error for invalid XML', function () {
    $user = User::factory()->create();
    $invalidXml = UploadedFile::fake()->createWithContent('invalid.xml', 'not valid xml');

    $response = $this->actingAs($user)
        ->post(route('upload-xml'), ['file' => $invalidXml]);

    $response->assertStatus(422)
        ->assertJson([
            'success' => false,
            'error' => [
                'category' => 'data',
                'code' => 'xml_parse_error',
            ],
        ]);
});

it('logs upload failure with context', function () {
    Log::shouldReceive('warning')
        ->once()
        ->withArgs(fn($msg, $ctx) => 
            str_contains($msg, 'XML upload') && 
            isset($ctx['filename']) && 
            isset($ctx['user_id'])
        );
    
    // ... test upload failure
});
```

**File:** `tests/pest/Feature/UploadIgsnCsvControllerTest.php`

```php
it('returns categorized errors for duplicate IGSNs', function () {
    $user = User::factory()->create();
    Resource::factory()->create(['doi' => 'IGSN123']);
    
    $csv = UploadedFile::fake()->createWithContent(
        'test.csv',
        "igsn|title|name\nIGSN123|Test|Author"
    );

    $response = $this->actingAs($user)
        ->post(route('upload-igsn-csv'), ['file' => $csv]);

    $response->assertStatus(422)
        ->assertJsonPath('errors.0.code', 'duplicate_igsn');
});
```

#### Task 4.2: Frontend Tests

**File:** `tests/vitest/components/unified-dropzone.test.tsx`

```typescript
it('shows toast notification for simple upload errors', async () => {
    const toastSpy = vi.spyOn(toast, 'error');
    
    // Mock failed upload response
    fetchMock.mockResponseOnce(JSON.stringify({
        success: false,
        message: 'File too large',
        error: { category: 'validation', code: 'file_too_large', message: 'Max 4MB' }
    }), { status: 422 });

    // ... trigger upload
    
    expect(toastSpy).toHaveBeenCalledWith(
        expect.stringContaining('Upload failed'),
        expect.objectContaining({ description: expect.any(String) })
    );
});

it('shows modal for multiple row errors', async () => {
    // ... test that modal opens when errors > 3
});
```

**File:** `tests/playwright/workflows/upload-error.spec.ts`

```typescript
test('displays upload error notification', async ({ page }) => {
    await page.goto('/dashboard');
    
    // Upload invalid file
    await page.setInputFiles('[data-testid="unified-file-input"]', {
        name: 'invalid.xml',
        mimeType: 'text/xml',
        buffer: Buffer.from('not valid xml'),
    });

    // Verify error toast appears
    await expect(page.locator('[data-sonner-toast]')).toContainText('Upload failed');
});
```

### Phase 5: Documentation

#### Task 5.1: Update Changelog

**File:** `resources/data/changelog.json`

```json
{
    "version": "X.X.X",
    "date": "YYYY-MM-DD",
    "features": [
        {
            "title": "Upload Failure Notifications",
            "description": "Data curators now receive detailed notifications when file uploads fail, including the filename and specific error reason. Complex errors (like multiple CSV row issues) are displayed in a modal dialog."
        }
    ],
    "improvements": [
        {
            "title": "Upload Error Logging",
            "description": "All upload failures are now logged with full context and visible on the /logs page for administrators."
        }
    ]
}
```

#### Task 5.2: Update User Documentation

**File:** `resources/js/pages/docs.tsx`

Add section about upload error handling under the appropriate workflow documentation.

## File Summary

### New Files
| File | Purpose |
|------|---------|
| `app/Support/UploadError.php` | DTO for structured error responses |
| `app/Enums/UploadErrorCode.php` | Centralized error codes |
| `app/Services/UploadLogService.php` | Upload failure logging service |
| `resources/js/types/upload.ts` | TypeScript types for upload responses |
| `resources/js/components/upload-error-modal.tsx` | Modal for complex errors |

### Modified Files
| File | Changes |
|------|---------|
| `app/Http/Controllers/UploadXmlController.php` | Add structured errors, logging |
| `app/Http/Controllers/UploadIgsnCsvController.php` | Enhance error categorization, logging |
| `app/Http/Requests/UploadXmlRequest.php` | Add error messages, logging |
| `resources/js/components/unified-dropzone.tsx` | Add toast, modal integration |
| `resources/js/pages/dashboard.tsx` | Add toast for XML errors |
| `resources/data/changelog.json` | Document new feature |
| `resources/js/pages/docs.tsx` | Document error handling |

### Test Files
| File | Purpose |
|------|---------|
| `tests/pest/Feature/UploadXmlControllerTest.php` | Backend XML upload tests |
| `tests/pest/Feature/UploadIgsnCsvControllerTest.php` | Backend CSV upload tests |
| `tests/vitest/components/unified-dropzone.test.tsx` | Frontend component tests |
| `tests/playwright/workflows/upload-error.spec.ts` | E2E upload error tests |

## Estimated Effort

| Phase | Tasks | Estimated Time |
|-------|-------|----------------|
| Phase 1: Backend | 5 tasks | 3-4 hours |
| Phase 2: Frontend | 4 tasks | 2-3 hours |
| Phase 3: Logging | 2 tasks | 1-2 hours |
| Phase 4: Testing | 2 tasks | 2-3 hours |
| Phase 5: Documentation | 2 tasks | 1 hour |
| **Total** | **15 tasks** | **9-13 hours** |

## Implementation Order

1. **Phase 1.1-1.2:** Create DTO and Enum (foundation)
2. **Phase 2.1:** Create TypeScript types (frontend foundation)
3. **Phase 1.3-1.5:** Update controllers and requests
4. **Phase 3:** Add logging service
5. **Phase 2.2-2.4:** Update frontend components
6. **Phase 4:** Write tests
7. **Phase 5:** Update documentation

## Rollback Plan

If issues arise:
1. All changes are additive - existing error handling continues to work
2. Toast notifications can be disabled by removing `toast()` calls
3. Error modal can be hidden by not setting `showErrorModal`
4. Logging can be disabled by removing service calls

## Open Questions

None - all requirements clarified.
