<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Your review link has changed</title>
</head>
<body style="margin: 0; background: #f5f5f5; color: #222; font-family: Arial, sans-serif; line-height: 1.5; padding: 24px;">
    <div style="background: #fff; border-radius: 8px; margin: 0 auto; max-width: 640px; padding: 32px;">
        <p>Dear {{ $recipientName }},</p>

        <p>With this automated email we want to inform you that your review link has changed due to a server migration on our side. This change is necessary to continue providing our services as quickly as possible.</p>

        <div style="background: #f3f6fa; border-left: 4px solid #0c2a63; margin: 20px 0; padding: 16px;">
            <p style="margin: 0 0 8px;"><strong>Title:</strong> {{ $resourceTitle }}</p>
            @if($resourceDoi)
                <p style="margin: 0 0 8px;"><strong>DOI:</strong> {{ $resourceDoi }}</p>
            @endif
            <p style="margin: 0;"><strong>Your new review link:</strong> <a href="{{ $reviewUrl }}">{{ $reviewUrl }}</a></p>
        </div>

        <p>The old review link is no longer valid. Therefore, if your work is currently under review by a journal, we kindly ask you to resend the updated review link to the reviewers to grant them access before your dataset is published.</p>

        <p>The DOI link is not affected by this change and can be cited as usual.</p>

        <p>We expect to be able to process data publication requests again starting September 3. Until then, we appreciate your patience.</p>

        <p>This is an automated mail. Please do not reply to the sender's address.</p>

        <p>If you have any questions, please contact us via:<br>
            <a href="mailto:{{ $contactAddress }}">{{ $contactAddress }}</a>
        </p>

        <p style="margin-bottom: 0;">
            Kind regards,<br>
            the data publication team at GFZ Data Services
        </p>
    </div>
</body>
</html>
