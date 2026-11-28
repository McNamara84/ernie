<p>{{ $actorName }} {{ $kind === 'mention' ? 'mentioned you in a curation note.' : 'assigned you to a resource or made you its lead curator.' }}</p>
<p><a href="{{ $resourceUrl }}">{{ $resourceTitle }}</a></p>
<p style="white-space: pre-wrap">{{ $messageText }}</p>
