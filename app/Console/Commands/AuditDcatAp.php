<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Resource;
use App\Services\DataCite\DataCiteDateFormatService;
use App\Services\DcatAp\DcatApGeometryMapper;
use App\Services\DcatAp\DcatApReadinessService;
use App\Services\DcatAp\DcatApResourceProfileService;
use App\Services\DcatAp\DcatApRightsMapper;
use App\Services\DcatAp\DcatApVocabularyMapper;
use App\Services\LandingPageContentLinkService;
use App\Support\IdentifierUrl;
use Illuminate\Console\Command;

final class AuditDcatAp extends Command
{
    protected $signature = 'dcat-ap:audit {--format=json : json or csv}';

    protected $description = 'Read-only DCAT-AP readiness report, including blocked records and optional omissions';

    public function handle(DcatApReadinessService $readiness, DcatApResourceProfileService $profile, DcatApRightsMapper $rights, DcatApVocabularyMapper $vocabulary, DcatApGeometryMapper $geometry, LandingPageContentLinkService $content): int
    {
        $format = $this->option('format');
        if (! in_array($format, ['json', 'csv'], true)) {
            $this->error('Supported formats: json, csv');

            return self::FAILURE;
        }
        $readiness->validateConfiguration();
        $first = true;
        $counts = ['total' => 0, 'available' => 0, 'blocked' => 0, 'with_warnings' => 0];
        $this->output->write($format === 'json' ? '{"records":[' : "resource_id,doi,type,published,tombstone,available,errors,warnings\n");
        Resource::query()->with($profile->requiredRelations())->chunkById(200, function ($resources) use ($readiness, $rights, $vocabulary, $geometry, $content, $format, &$first, &$counts): void {
            foreach ($resources as $resource) {
                $errors = $readiness->errors($resource);
                $warnings = [];
                if ($rights->resolve($resource)['ambiguous']) {
                    $warnings[] = 'ambiguous_licenses';
                }
                if ($resource->language !== null && $vocabulary->language($resource->language->code) === null) {
                    $warnings[] = 'unmapped_language';
                }
                foreach ($resource->dates as $date) {
                    foreach ([$date->date_value, $date->start_date, $date->end_date] as $value) {
                        if ($value !== null && ! DataCiteDateFormatService::isDate($value)) {
                            $warnings[] = 'invalid_optional_date';
                        }
                    }
                }
                foreach ($resource->relatedIdentifiers as $identifier) {
                    if (IdentifierUrl::resolve($identifier->identifier, $identifier->identifierType->slug) === null) {
                        $warnings[] = 'unresolved_related_identifier';
                    }
                }
                foreach ($resource->geoLocations as $location) {
                    if (($location->hasPoint() || $location->hasBox() || $location->hasLine() || $location->hasPolygon()) && $geometry->map($location) === null) {
                        $warnings[] = 'invalid_optional_geometry';
                    }
                }
                if ($resource->landingPage !== null) {
                    foreach ($content->resolve($resource, $resource->landingPage)['contentLinks'] as $link) {
                        if ($vocabulary->mediaType($link['mimeType']) === null) {
                            $warnings[] = 'unregistered_media_type';
                        }
                    }
                }
                $row = ['resource_id' => $resource->id, 'doi' => $resource->doi, 'type' => $resource->resourceType?->slug,
                    'published' => (bool) $resource->landingPage?->is_published, 'tombstone' => (bool) $resource->landingPage?->is_tombstone,
                    'available' => $errors === [], 'errors' => $errors, 'warnings' => array_values(array_unique($warnings))];
                if ($format === 'json') {
                    $this->output->write(($first ? '' : ',')."\n".json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
                } else {
                    $stream = fopen('php://temp', 'r+');
                    if ($stream === false) {
                        throw new \RuntimeException('Cannot prepare CSV row');
                    }
                    $row['errors'] = implode('|', $errors);
                    $row['warnings'] = implode('|', $row['warnings']);
                    fputcsv($stream, array_values($row), ',', '"', '');
                    rewind($stream);
                    $this->output->write((string) stream_get_contents($stream));
                    fclose($stream);
                }
                $first = false;
                $counts['total']++;
                $counts[$errors === [] ? 'available' : 'blocked']++;
                if ($warnings !== []) {
                    $counts['with_warnings']++;
                }
            }
        });
        if ($format === 'json') {
            $this->output->write("\n],\"summary\":".json_encode($counts, JSON_THROW_ON_ERROR)."}\n");
        }

        return self::SUCCESS;
    }
}
