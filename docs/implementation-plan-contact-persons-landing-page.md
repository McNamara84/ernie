# Implementation Plan: Contact Persons on Landing Pages

## Overview

This feature adds a "Contact Information" section to landing pages, allowing visitors to contact dataset contact persons without exposing their email addresses. Messages are sent via a server-side email system.

## User's Requirements Summary

| Question | Answer | Description |
|----------|--------|-------------|
| Sender Identification | B | Guests can send, must provide name + email |
| Spam Protection | E | Honeypot + Rate Limiting |
| Email Format | B | HTML email with ERNIE branding |
| Confirmation Email | C | Optional via checkbox |
| Modal Behavior | A | Clicked person pre-selected, "Send to all" checkbox available |

---

## Phase 1: Database Foundation

### 1.1 Migration: Add email column to resource_contributors

**File:** `database/migrations/YYYY_MM_DD_HHMMSS_add_email_to_resource_contributors.php`

```php
Schema::table('resource_contributors', function (Blueprint $table) {
    $table->string('email')->nullable()->after('position');
});
```

### 1.2 Update ResourceContributor Model

**File:** `app/Models/ResourceContributor.php`

- Add `email` to `$fillable` array
- Add `email` to PHPDoc `@property`

---

## Phase 2: Editor Adjustments

### 2.1 Frontend: Add email field for ContactPerson contributors

**File:** `resources/js/components/curation/datacite-form.tsx`

- Add `email` field to `PersonContributorEntry` type
- Show email input when contributor has role "Contact Person"
- Validate email is required when ContactPerson role is selected

### 2.2 Backend: Save ContactPerson email

**File:** `app/Http/Controllers/ResourceController.php`

- Update `storePersonContributor()` to accept and save email
- Only save email for contributors with ContactPerson type

### 2.3 Backend: Validate ContactPerson email

**File:** `app/Http/Requests/StoreResourceRequest.php`

- Add validation rule: email required when contributor has ContactPerson role

---

## Phase 3: Contact Message Infrastructure

### 3.1 Create ContactMessage Model (for logging/rate limiting)

**File:** `app/Models/ContactMessage.php`

```php
// Properties: resource_id, sender_name, sender_email, recipient_ids (JSON), 
//             message, ip_address, sent_at, honeypot_triggered
```

### 3.2 Migration: Create contact_messages table

**File:** `database/migrations/YYYY_MM_DD_HHMMSS_create_contact_messages_table.php`

```php
Schema::create('contact_messages', function (Blueprint $table) {
    $table->id();
    $table->foreignId('resource_id')->constrained()->cascadeOnDelete();
    $table->string('sender_name');
    $table->string('sender_email');
    $table->json('recipient_contributor_ids'); // Array of ResourceContributor IDs
    $table->text('message');
    $table->string('ip_address', 45)->nullable();
    $table->boolean('honeypot_triggered')->default(false);
    $table->boolean('send_copy_to_sender')->default(false);
    $table->timestamps();
    
    $table->index(['resource_id', 'created_at']);
    $table->index(['ip_address', 'created_at']); // For rate limiting
});
```

### 3.3 Create Mailable: ContactPersonMessage

**File:** `app/Mail/ContactPersonMessage.php`

- HTML template with ERNIE branding
- Contains: Dataset title, DOI, sender info, message
- Reply-To set to sender's email

### 3.4 Create Mailable: ContactMessageCopy (for sender)

**File:** `app/Mail/ContactMessageCopy.php`

- Confirmation email to sender
- Contains: Copy of message, recipient names, dataset info

### 3.5 Create Mail Views

**Files:**
- `resources/views/emails/contact-person-message.blade.php`
- `resources/views/emails/contact-message-copy.blade.php`

---

## Phase 4: Contact API Endpoint

### 4.1 Create Form Request: SendContactMessageRequest

**File:** `app/Http/Requests/SendContactMessageRequest.php`

```php
public function rules(): array
{
    return [
        'resource_id' => ['required', 'exists:resources,id'],
        'sender_name' => ['required', 'string', 'max:255'],
        'sender_email' => ['required', 'email', 'max:255'],
        'message' => ['required', 'string', 'min:10', 'max:5000'],
        'recipient_ids' => ['required', 'array', 'min:1'],
        'recipient_ids.*' => ['exists:resource_contributors,id'],
        'send_copy' => ['boolean'],
        'website' => ['max:0'], // Honeypot field - must be empty
    ];
}
```

### 4.2 Create Controller: ContactMessageController

**File:** `app/Http/Controllers/ContactMessageController.php`

```php
public function send(SendContactMessageRequest $request): JsonResponse
{
    // 1. Check honeypot (if 'website' field is filled, it's a bot)
    // 2. Check rate limit (max 5 messages per hour per IP)
    // 3. Validate recipients belong to the resource and are ContactPersons
    // 4. Log message to contact_messages table
    // 5. Queue emails to recipients
    // 6. Optionally queue copy to sender
    // 7. Return success response
}
```

### 4.3 Create Service: ContactMessageService

**File:** `app/Services/ContactMessageService.php`

- Handles rate limiting logic
- Sends emails via queue
- Logs messages

### 4.4 Register Route

**File:** `routes/web.php`

```php
// Public route (no auth required, but rate limited)
Route::post('/contact/send', [ContactMessageController::class, 'send'])
    ->name('contact.send')
    ->middleware('throttle:contact-messages');
```

### 4.5 Configure Rate Limiter

**File:** `app/Providers/AppServiceProvider.php`

```php
RateLimiter::for('contact-messages', function (Request $request) {
    return Limit::perHour(5)->by($request->ip());
});
```

---

## Phase 5: Landing Page Frontend

### 5.1 Update LandingPagePublicController

**File:** `app/Http/Controllers/LandingPagePublicController.php`

- Include ContactPerson contributors with email in response
- Filter contributors to only return ContactPersons with email
- Do NOT expose email addresses to frontend

```php
// Add to resource data preparation:
$contactPersons = $resource->contributors
    ->filter(fn($c) => $c->contributorType->slug === 'ContactPerson' && $c->email)
    ->map(fn($c) => [
        'id' => $c->id,
        'name' => $c->contributorable->full_name ?? $c->contributorable->name,
        'type' => class_basename($c->contributorable_type),
    ]);
```

### 5.2 Create ContactSection Component

**File:** `resources/js/Pages/LandingPages/components/ContactSection.tsx`

```tsx
interface ContactPerson {
    id: number;
    name: string;
    type: string; // 'Person' | 'Institution'
}

interface ContactSectionProps {
    contactPersons: ContactPerson[];
    resourceId: number;
    resourceTitle: string;
}
```

Features:
- List of contact person names as clickable links
- Opens ContactModal on click

### 5.3 Create ContactModal Component

**File:** `resources/js/Pages/LandingPages/components/ContactModal.tsx`

Features:
- Dialog/Modal using Radix UI
- Form fields:
  - Your Name (required)
  - Your Email (required)
  - Message (required, textarea)
  - Honeypot field (hidden, CSS: `position: absolute; left: -9999px`)
  - Checkbox: "Send to all contact persons" (if multiple)
  - Checkbox: "Send me a copy"
- Submit button with loading state
- Success/Error feedback
- Pre-selects clicked contact person

### 5.4 Integrate ContactSection into default_gfz Template

**File:** `resources/js/Pages/LandingPages/default_gfz.tsx`

- Add ContactSection to left column (after FilesSection)
- Pass contactPersons, resourceId, resourceTitle props

---

## Phase 6: Remove Legacy isContact from Creators

### 6.1 Clean up Frontend

**File:** `resources/js/components/curation/datacite-form.tsx`

- Remove `isContact`, `email`, `website` from PersonAuthorEntry
- Remove contact person checkbox from author form
- Update serialization to not send these fields

### 6.2 Clean up Backend

**File:** `app/Http/Requests/StoreResourceRequest.php`

- Remove `isContact` validation and processing for authors

### 6.3 Migration: Remove legacy columns (optional, can be deferred)

**File:** `database/migrations/YYYY_MM_DD_HHMMSS_remove_legacy_contact_fields_from_resource_creators.php`

```php
Schema::table('resource_creators', function (Blueprint $table) {
    $table->dropColumn(['email', 'website']);
});
```

> **Note:** This migration should only run after confirming no data loss. May need data migration script first.

---

## File Summary

### New Files (13)

| File | Purpose |
|------|---------|
| `database/migrations/*_add_email_to_resource_contributors.php` | Add email column |
| `database/migrations/*_create_contact_messages_table.php` | Contact message logging |
| `app/Models/ContactMessage.php` | Contact message model |
| `app/Mail/ContactPersonMessage.php` | Email to contact person |
| `app/Mail/ContactMessageCopy.php` | Copy email to sender |
| `resources/views/emails/contact-person-message.blade.php` | Email template |
| `resources/views/emails/contact-message-copy.blade.php` | Copy email template |
| `app/Http/Requests/SendContactMessageRequest.php` | Form validation |
| `app/Http/Controllers/ContactMessageController.php` | API endpoint |
| `app/Services/ContactMessageService.php` | Business logic |
| `resources/js/Pages/LandingPages/components/ContactSection.tsx` | UI component |
| `resources/js/Pages/LandingPages/components/ContactModal.tsx` | Modal component |

### Modified Files (8)

| File | Changes |
|------|---------|
| `app/Models/ResourceContributor.php` | Add email to fillable |
| `app/Http/Controllers/ResourceController.php` | Save contributor email |
| `app/Http/Requests/StoreResourceRequest.php` | Validate contributor email |
| `app/Http/Controllers/LandingPagePublicController.php` | Include contact persons |
| `app/Providers/AppServiceProvider.php` | Rate limiter config |
| `routes/web.php` | Contact route |
| `resources/js/components/curation/datacite-form.tsx` | Email field for ContactPerson |
| `resources/js/Pages/LandingPages/default_gfz.tsx` | Integrate ContactSection |

---

## Implementation Order

1. **Phase 1** - Database (migration + model update)
2. **Phase 2** - Editor (frontend + backend for saving ContactPerson email)
3. **Phase 3** - Email infrastructure (models, mailables, views)
4. **Phase 4** - API endpoint (controller, service, route, rate limiting)
5. **Phase 5** - Landing page UI (components, integration)
6. **Phase 6** - Cleanup legacy code (optional, can be separate PR)

---

## Testing Requirements

### Unit Tests
- `ContactMessageService` rate limiting logic
- Email validation
- Honeypot detection

### Feature Tests
- `SendContactMessageRequest` validation
- `ContactMessageController` endpoint
- Rate limiting behavior
- Successful email sending (using Mail::fake())

### Playwright E2E Tests
- Contact modal opens on click
- Form validation works
- Success message shown after send
- "Send to all" checkbox works

---

## Security Considerations

1. **No email exposure**: Contact person emails never sent to frontend
2. **Honeypot**: Invisible field catches basic bots
3. **Rate limiting**: 5 messages per hour per IP
4. **Input validation**: All fields validated, message length limited
5. **CSRF protection**: Laravel's built-in CSRF for form submission
6. **XSS prevention**: All user input escaped in email templates

---

## Decisions Made

1. **Contact person count**: No, not required
2. **Character counter**: Yes, show remaining characters (limit: 5000)
3. **Subject line**: Auto-generated (e.g., "Message regarding dataset: [Title]")
4. **Email queue**: Use existing application queue (low volume expected)

---

## Estimated Effort

| Phase | Effort |
|-------|--------|
| Phase 1: Database | 0.5h |
| Phase 2: Editor | 2h |
| Phase 3: Email Infrastructure | 2h |
| Phase 4: API Endpoint | 2h |
| Phase 5: Landing Page UI | 3h |
| Phase 6: Cleanup | 1h |
| Testing | 2h |
| **Total** | **~12.5h** |
