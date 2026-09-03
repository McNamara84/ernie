<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ERNIE feedback</title>
</head>
<body style="margin: 0; background: #f5f5f5; color: #222; font-family: Arial, sans-serif; line-height: 1.5; padding: 24px;">
    <div style="background: #fff; border-radius: 8px; margin: 0 auto; max-width: 720px; padding: 32px;">
        <h1 style="color: #0c2a63; font-size: 22px; margin: 0 0 24px;">New ERNIE feedback</h1>

        <p>Dear {{ $recipientName }},</p>

        <div style="background: #f3f6fa; border-left: 4px solid #0c2a63; margin: 20px 0; padding: 16px;">
            <p style="margin: 0 0 8px;"><strong>Category:</strong> {{ $categoryLabel }}</p>
            <p style="margin: 0 0 8px;"><strong>Submitted:</strong> {{ $submittedAt }}</p>
            <p style="margin: 0;"><strong>Feedback ID:</strong> {{ $feedbackId }}</p>
        </div>

        <h2 style="font-size: 17px; margin: 28px 0 8px;">Feedback</h2>
        <div style="background: #fafafa; border: 1px solid #ddd; border-radius: 6px; padding: 16px; white-space: pre-wrap;">{{ $feedbackMessage }}</div>

        <h2 style="font-size: 17px; margin: 28px 0 8px;">Submitted by</h2>
        <p style="margin: 0;">
            {{ $submittedByName }} ({{ $submittedByRole }})<br>
            <a href="mailto:{{ $submittedByEmail }}">{{ $submittedByEmail }}</a>
        </p>

        <h2 style="font-size: 17px; margin: 28px 0 8px;">Page and environment</h2>
        <table role="presentation" style="border-collapse: collapse; width: 100%;">
            <tr><th style="padding: 4px 12px 4px 0; text-align: left; vertical-align: top;">Page</th><td style="padding: 4px 0;">{{ $page['title'] }}</td></tr>
            <tr><th style="padding: 4px 12px 4px 0; text-align: left; vertical-align: top;">Path</th><td style="padding: 4px 0;"><code>{{ $page['path'] }}</code></td></tr>
            <tr><th style="padding: 4px 12px 4px 0; text-align: left; vertical-align: top;">Appearance</th><td style="padding: 4px 0;">{{ $environment['appearance'] }} (resolved: {{ $environment['resolved_theme'] }})</td></tr>
            <tr><th style="padding: 4px 12px 4px 0; text-align: left; vertical-align: top;">Viewport</th><td style="padding: 4px 0;">{{ $environment['viewport_width'] }} × {{ $environment['viewport_height'] }} CSS px (DPR {{ $environment['device_pixel_ratio'] }})</td></tr>
            <tr><th style="padding: 4px 12px 4px 0; text-align: left; vertical-align: top;">Locale</th><td style="padding: 4px 0;">{{ $environment['locale'] }}</td></tr>
            <tr><th style="padding: 4px 12px 4px 0; text-align: left; vertical-align: top;">Time zone</th><td style="padding: 4px 0;">{{ $environment['timezone'] }}</td></tr>
            <tr><th style="padding: 4px 12px 4px 0; text-align: left; vertical-align: top;">Browser / User-Agent</th><td style="overflow-wrap: anywhere; padding: 4px 0;"><code>{{ $userAgent }}</code></td></tr>
        </table>

        <h2 style="font-size: 17px; margin: 28px 0 8px;">Recent session diagnostics ({{ count($diagnostics) }})</h2>
        @forelse($diagnostics as $event)
            <div style="background: #fafafa; border: 1px solid #ddd; border-radius: 6px; margin: 0 0 8px; padding: 10px 12px;">
                <strong>{{ $event['type'] }}</strong> — {{ $event['occurred_at'] }}
                @if(isset($event['method']))<br><code>{{ $event['method'] }}</code>@endif
                @if(isset($event['path'])) <code>{{ $event['path'] }}</code>@endif
                @if(isset($event['status'])) — HTTP {{ $event['status'] }}@endif
                @if(isset($event['message']))<br>{{ $event['message'] }}@endif
            </div>
        @empty
            <p style="color: #666;">No diagnostic events were available for this browser session.</p>
        @endforelse

        <p style="border-top: 1px solid #ddd; color: #666; font-size: 12px; margin-top: 28px; padding-top: 16px;">
            This message was submitted through the authenticated ERNIE feedback form. Replying sends your response directly to the submitter.
        </p>
    </div>
</body>
</html>
