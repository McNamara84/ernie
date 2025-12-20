<x-mail::message>
# Confirmation: Your Message Was Sent

Dear {{ $senderName }},

This is a confirmation that your message has been sent to the contact person(s) of the following dataset.

---

## Dataset Information

**Title:** {{ $datasetTitle }}

@if($doi)
**DOI:** [{{ $doi }}]({{ $doiUrl }})
@endif

---

## Your Message

{{ $messageContent }}

---

## Recipients

Your message was sent to:

@foreach($recipientNames as $name)
- {{ $name }}
@endforeach

---

If you receive a reply, it will be sent directly to your email address.

Best regards,<br>
{{ config('app.name') }} - GFZ Data Services
</x-mail::message>
