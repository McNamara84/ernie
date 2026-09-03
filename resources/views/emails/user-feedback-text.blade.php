GFZ DATA SERVICES - ERNIE FEEDBACK

Dear {{ $recipientName }},

Category: {{ $categoryLabel }}
Submitted: {{ $submittedAt }}
Feedback ID: {{ $feedbackId }}

FEEDBACK
{{ $feedbackMessage }}

SUBMITTED BY
{{ $submittedByName }} ({{ $submittedByRole }})
{{ $submittedByEmail }}

PAGE AND ENVIRONMENT
Page: {{ $page['title'] }}
Path: {{ $page['path'] }}
Appearance: {{ $environment['appearance'] }} (resolved: {{ $environment['resolved_theme'] }})
Viewport: {{ $environment['viewport_width'] }} x {{ $environment['viewport_height'] }} CSS px (DPR {{ $environment['device_pixel_ratio'] }})
Locale: {{ $environment['locale'] }}
Time zone: {{ $environment['timezone'] }}
Browser / User-Agent: {{ $userAgent }}

RECENT SESSION DIAGNOSTICS ({{ count($diagnostics) }})
@forelse($diagnostics as $event)
- {{ $event['occurred_at'] }} {{ $event['type'] }}@if(isset($event['method'])) {{ $event['method'] }}@endif @if(isset($event['path'])){{ $event['path'] }}@endif @if(isset($event['status']))HTTP {{ $event['status'] }}@endif
@if(isset($event['message']))  {{ $event['message'] }}
@endif
@empty
No diagnostic events were available for this browser session.
@endforelse

This message was submitted through the authenticated ERNIE feedback form. Replying sends your response directly to the submitter.
