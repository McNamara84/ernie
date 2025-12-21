<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Contact Request - GFZ Data Services</title>
    <style>
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;
            line-height: 1.6;
            color: #333;
            max-width: 600px;
            margin: 0 auto;
            padding: 20px;
            background-color: #f5f5f5;
        }
        .container {
            background-color: #ffffff;
            border-radius: 8px;
            padding: 30px;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
        }
        .header {
            text-align: center;
            border-bottom: 2px solid #0C2A63;
            padding-bottom: 20px;
            margin-bottom: 25px;
        }
        .header img {
            max-height: 50px;
        }
        .header h1 {
            color: #0C2A63;
            font-size: 20px;
            margin: 15px 0 0 0;
        }
        .copy-notice {
            background-color: #e3f2fd;
            border-left: 4px solid #2196f3;
            padding: 12px 16px;
            margin-bottom: 20px;
            border-radius: 0 4px 4px 0;
        }
        .copy-notice p {
            margin: 0;
            color: #1565c0;
        }
        .greeting {
            font-size: 16px;
            margin-bottom: 20px;
        }
        .message-box {
            background-color: #f8f9fa;
            border-left: 4px solid #0C2A63;
            padding: 15px 20px;
            margin: 20px 0;
            border-radius: 0 4px 4px 0;
        }
        .message-box p {
            margin: 0;
            white-space: pre-wrap;
        }
        .sender-info {
            background-color: #fff3e0;
            border: 1px solid #ffe0b2;
            border-radius: 4px;
            padding: 15px;
            margin: 20px 0;
        }
        .sender-info h3 {
            margin: 0 0 10px 0;
            color: #e65100;
            font-size: 14px;
        }
        .sender-info p {
            margin: 5px 0;
        }
        .dataset-info {
            background-color: #e8f5e9;
            border: 1px solid #c8e6c9;
            border-radius: 4px;
            padding: 15px;
            margin: 20px 0;
        }
        .dataset-info h3 {
            margin: 0 0 10px 0;
            color: #2e7d32;
            font-size: 14px;
        }
        .dataset-info p {
            margin: 5px 0;
        }
        .dataset-info a {
            color: #1565c0;
            text-decoration: none;
        }
        .dataset-info a:hover {
            text-decoration: underline;
        }
        .footer {
            margin-top: 30px;
            padding-top: 20px;
            border-top: 1px solid #e0e0e0;
            text-align: center;
            font-size: 12px;
            color: #666;
        }
        .footer img {
            max-height: 40px;
            margin: 10px;
        }
        .footer a {
            color: #0C2A63;
            text-decoration: none;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <img src="https://dataservices.gfz-potsdam.de/images/gfz-ds-logo.png" alt="GFZ Data Services">
            <h1>Contact Request</h1>
        </div>

        @if($isCopyToSender)
        <div class="copy-notice">
            <p><strong>This is a copy of your message sent via GFZ Data Services.</strong></p>
        </div>
        @endif

        <p class="greeting">Dear {{ $recipientName }},</p>

        <p>You have received a contact request regarding the following dataset:</p>

        <div class="dataset-info">
            <h3>Dataset Information</h3>
            <p><strong>Title:</strong> {{ $datasetTitle }}</p>
            @if($datasetDoi)
            <p><strong>DOI:</strong> {{ $datasetDoi }}</p>
            @endif
            <p><strong>Link:</strong> <a href="{{ $datasetUrl }}">{{ $datasetUrl }}</a></p>
        </div>

        <div class="sender-info">
            <h3>Sender Information</h3>
            <p><strong>Name:</strong> {{ $senderName }}</p>
            <p><strong>Email:</strong> <a href="mailto:{{ $senderEmail }}">{{ $senderEmail }}</a></p>
        </div>

        <p><strong>Message:</strong></p>
        <div class="message-box">
            <p>{{ $messageContent }}</p>
        </div>

        <p>You can reply directly to this email to respond to the sender.</p>

        <div class="footer">
            <p>
                <a href="https://www.gfz-potsdam.de">
                    <img src="https://dataservices.gfz-potsdam.de/images/gfz-logo-en.gif" alt="GFZ">
                </a>
                <a href="https://www.helmholtz.de">
                    <img src="https://dataservices.gfz-potsdam.de/images/helmholtz-logo-blue.png" alt="Helmholtz">
                </a>
            </p>
            <p>
                GFZ German Research Centre for Geosciences<br>
                <a href="https://dataservices.gfz-potsdam.de">dataservices.gfz-potsdam.de</a>
            </p>
        </div>
    </div>
</body>
</html>
