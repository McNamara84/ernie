<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Inertia\Inertia;
use Inertia\Response;

final class SchemaCatalogController extends Controller
{
    public function __invoke(): Response
    {
        $locale = (string) config('app.locale', 'en');
        $contentPath = resource_path('data/schema-docs/catalog/'.$locale.'.json');
        if (! is_file($contentPath)) {
            $locale = 'en';
            $contentPath = resource_path('data/schema-docs/catalog/en.json');
        }
        $content = json_decode((string) file_get_contents($contentPath), true, flags: JSON_THROW_ON_ERROR);
        $igsnRevision = (string) config('igsn_core.revision');
        $dataciteRevision = (string) config('datacite.schemas.revision');
        $profiles = [
            ['id' => 'igsn-core', 'version' => '2.0', 'revision' => $igsnRevision, 'base' => '/schemas/igsn/2.0', 'file' => 'core.xsd', 'docs' => '/schemas/igsn/2.0/docs'],
            ['id' => 'datacite-json', 'version' => '4.7', 'revision' => $dataciteRevision, 'base' => '/schemas/datacite/4.7', 'file' => 'schema.json', 'docs' => '/schemas/datacite/4.7/docs/json'],
            ['id' => 'datacite-jsonld', 'version' => '4.7', 'revision' => $dataciteRevision, 'base' => '/schemas/datacite/4.7', 'file' => 'context.jsonld', 'docs' => '/schemas/datacite/4.7/docs/jsonld'],
        ];
        $entries = array_map(fn (array $profile): array => [
            ...$content['entries'][$profile['id']],
            'id' => $profile['id'], 'version' => $profile['version'], 'revision' => $profile['revision'],
            'documentationUrl' => url($profile['docs']),
            'artifactPath' => $profile['base'].'/'.$profile['file'],
            'artifactUrl' => url($profile['base'].'/'.$profile['file']),
            'fixedArtifactUrl' => url($profile['base'].'/'.$profile['revision'].'/'.$profile['file']),
            'manifestUrl' => url($profile['base'].'/'.$profile['revision'].'/manifest.json'),
        ], $profiles);

        return Inertia::render('schemas/catalog', [
            'catalog' => [
                'locale' => $locale, 'copy' => $content['interface'], 'entries' => $entries,
            ],
        ]);
    }
}
