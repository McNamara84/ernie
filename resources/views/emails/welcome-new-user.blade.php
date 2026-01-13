<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Welcome to ERNIE</title>
</head>
<body style="font-family: Arial, sans-serif; line-height: 1.6; color: #333; max-width: 600px; margin: 0 auto; padding: 20px; background-color: #f5f5f5;">
    <div style="background-color: #ffffff; padding: 30px; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1);">
        <h1 style="color: #1a1a1a; margin-bottom: 24px; font-size: 24px;">Welcome to ERNIE!</h1>
        
        <p style="margin-bottom: 16px;">Hello {{ $userName }},</p>
        
        <p style="margin-bottom: 16px;">I'm ERNIE, your metadata curation assistant at GFZ German Research Centre for Geosciences. An account has been created for you, and I'm excited to help you manage research dataset metadata according to the DataCite schema.</p>
        
        <p style="margin-bottom: 24px;">To get started, please set your password by clicking the button below:</p>
        
        <div style="text-align: center; margin: 32px 0;">
            <a href="{{ $welcomeUrl }}" 
               style="background-color: #2563eb; color: #ffffff; padding: 14px 28px; text-decoration: none; border-radius: 6px; display: inline-block; font-weight: 600; font-size: 16px;">
                Set Your Password
            </a>
        </div>
        
        <p style="color: #666666; font-size: 14px; margin-bottom: 16px;">This link will expire in {{ $expiresIn }}. If the link expires, you can request a new one on the welcome page.</p>
        
        <p style="color: #666666; font-size: 14px; margin-bottom: 24px;">If you didn't expect this email, you can safely ignore it.</p>
        
        <hr style="border: none; border-top: 1px solid #e5e5e5; margin: 24px 0;">
        
        <p style="color: #888888; font-size: 12px; margin: 0;">
            ERNIE – Metadata Curation System<br>
            GFZ German Research Centre for Geosciences
        </p>
    </div>
    
    <p style="color: #999999; font-size: 11px; text-align: center; margin-top: 16px; overflow-wrap: break-word; word-wrap: break-word;">
        If the button above doesn't work, copy and paste this link into your browser:<br>
        <a href="{{ $welcomeUrl }}" style="color: #2563eb; word-break: break-all; overflow-wrap: break-word;">{{ $welcomeUrl }}</a>
    </p>
</body>
</html>
