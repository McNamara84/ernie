<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Resource review link</title>
</head>
<body style="margin: 0; background: #f5f5f5; color: #222; font-family: Arial, sans-serif; line-height: 1.5; padding: 24px;">
    <div style="background: #fff; border-radius: 8px; margin: 0 auto; max-width: 640px; padding: 32px;">
        <h1 style="color: #0c2a63; font-size: 22px; margin: 0 0 24px;">Resource review requested</h1>

        <p>Dear {{ $recipientName }},</p>

        <p>Please review the following resource before publication:</p>

        <div style="background: #f3f6fa; border-left: 4px solid #0c2a63; margin: 20px 0; padding: 16px;">
            <p style="margin: 0 0 8px;"><strong>Title:</strong> {{ $resourceTitle }}</p>
            @if($resourceDoi)
                <p style="margin: 0 0 8px;"><strong>DOI:</strong> {{ $resourceDoi }}</p>
            @endif
            <p style="margin: 0;"><strong>Review link:</strong> <a href="{{ $reviewUrl }}">{{ $reviewUrl }}</a></p>
        </div>

        <p>If you have questions or feedback, please contact:</p>
        <p>
            {{ $initiatorName }}<br>
            <a href="mailto:{{ $initiatorEmail }}">{{ $initiatorEmail }}</a>
        </p>

        <p style="border-top: 1px solid #ddd; color: #666; font-size: 12px; margin-top: 28px; padding-top: 16px;">
            This review link provides access to a non-public preview. Please do not forward it beyond the intended review group.
        </p>
    </div>
</body>
</html>
