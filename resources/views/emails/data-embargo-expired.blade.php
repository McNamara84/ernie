<p>The data embargo for the following resources has expired. Please review and release the data in ERNIE.</p>
<dl>
    <dt>Title</dt><dd>{{ $resourceTitle }}</dd>
    @if ($identifier)<dt>DOI/IGSN</dt><dd>{{ $identifier }}</dd>@endif
    <dt>Available</dt><dd>{{ \Illuminate\Support\Carbon::parse($availableDate)->format('d.m.Y') }}</dd>
</dl>
<p><a href="{{ $editorUrl }}">Open Data Editor</a></p>
@if ($landingPageUrl)
<p><a href="{{ $landingPageUrl }}">Open landing page</a></p>
@else
<p>The metadata landing page has not been published.</p>
@endif
