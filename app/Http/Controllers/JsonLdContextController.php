<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\DataCiteJsonLdContextService;
use DateTimeImmutable;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class JsonLdContextController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $path = base_path(DataCiteJsonLdContextService::FILE);
        $content = (string) file_get_contents($path);
        $response = response($content, Response::HTTP_OK, ['Content-Type' => 'application/ld+json; charset=UTF-8']);
        $response->setPublic()->setMaxAge(31536000)->setImmutable();
        $response->setEtag(hash('sha256', $content));
        $response->setLastModified(new DateTimeImmutable('@'.filemtime($path)));
        $response->isNotModified($request);

        return $response;
    }
}
