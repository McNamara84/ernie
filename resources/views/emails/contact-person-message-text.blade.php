================================================================================
                        GFZ DATA SERVICES - CONTACT REQUEST
================================================================================

@if($isCopyToSender)
>>> This is a copy of your message sent via GFZ Data Services. <<<

@endif
Dear {{ $recipientName }},

You have received a contact request regarding the following dataset:

--------------------------------------------------------------------------------
DATASET INFORMATION
--------------------------------------------------------------------------------
Title: {{ $datasetTitle }}
@if($datasetDoi)
DOI:   {{ $datasetDoi }}
@endif
Link:  {{ $datasetUrl }}

--------------------------------------------------------------------------------
SENDER INFORMATION
--------------------------------------------------------------------------------
Name:  {{ $senderName }}
Email: {{ $senderEmail }}

--------------------------------------------------------------------------------
MESSAGE
--------------------------------------------------------------------------------

{{ $messageContent }}

--------------------------------------------------------------------------------

You can reply directly to this email to respond to the sender.

================================================================================
GFZ German Research Centre for Geosciences
https://dataservices.gfz-potsdam.de
================================================================================
