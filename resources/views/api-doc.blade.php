<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>API Documentation</title>
</head>
<body>
<main id="main-content">
    <div id="swagger-ui" aria-label="API documentation"></div>
    <script>window.__spec__ = @json($spec);</script>
    @unless(app()->environment('testing'))
        @vite('resources/js/swagger.tsx')
    @endunless
</main>
</body>
</html>
