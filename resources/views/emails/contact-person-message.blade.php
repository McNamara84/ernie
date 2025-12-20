<x-mail::message>
# New Message Regarding Your Dataset

Dear {{ $recipientName }},

You have received a new message from a visitor of the GFZ Data Services landing page.

---

## Dataset Information

**Title:** {{ $datasetTitle }}

@if($doi)
**DOI:** [{{ $doi }}]({{ $doiUrl }})
@endif

---

## Message from {{ $senderName }}

{{ $messageContent }}

---

**Sender:** {{ $senderName }} ({{ $senderEmail }})

You can reply directly to this email to respond to the sender.

Best regards,<br>
{{ config('app.name') }} - GFZ Data Services
</x-mail::message>
