The data embargo for the following resources has expired. Please review and release the data in ERNIE.

Title: {{ $resourceTitle }}
@if ($identifier)
DOI/IGSN: {{ $identifier }}
@endif
Available: {{ \Illuminate\Support\Carbon::parse($availableDate)->format('d.m.Y') }}
Data Editor: {{ $editorUrl }}
@if ($landingPageUrl)
Landing page: {{ $landingPageUrl }}
@else
The metadata landing page has not been published.
@endif
