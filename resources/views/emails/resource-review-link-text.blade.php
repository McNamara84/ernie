Dear {{ $recipientName }},

with this automated email we want to inform you that your review link has changed due to a server migration on our side. This change is necessary to continue providing our services as quickly as possible.

Title: {{ $resourceTitle }}
@if($resourceDoi)
DOI: {{ $resourceDoi }}
@endif
Your new review link: {{ $reviewUrl }}

The old review link is no longer valid. Therefore, if your work is currently under review by a journal, we kindly ask you to resend the updated review link to the reviewers to grant them access before your dataset is published.

The DOI link is not affected by this change and can be cited as usual.

We expect to be able to process data publication requests again starting September 3. Until then, we appreciate your patience.

This is an automated mail. Please do not reply to the sender's address.

If you have any questions, please contact us via:
{{ $contactAddress }}

Kind regards,
the data publication team at GFZ Data Services
