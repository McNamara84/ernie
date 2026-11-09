<?php

declare(strict_types=1);

namespace App\Services\DcatAp;

use App\Models\Resource;
use App\Support\PortalSubjectNormalizer;
use App\Support\SubjectBreadcrumbPath;

final class DcatApDataThemeResolver
{
    /** @return list<string> */
    public function resolve(Resource $resource): array
    {
        $themes = ['TECH', 'ENVI'];
        foreach ($resource->subjects as $subject) {
            $scheme = PortalSubjectNormalizer::normalizeScheme($subject->subject_scheme);
            if (in_array(mb_strtolower($scheme ?? ''), ['gcmd', 'nasa/gcmd'], true)) {
                $scheme = 'Science Keywords';
            }
            $segments = SubjectBreadcrumbPath::segments(SubjectBreadcrumbPath::preferredPath($subject->breadcrumb_path, $subject->value));
            if ($segments !== [] && SubjectBreadcrumbPath::isSchemeHeading($segments[0], $scheme ?? '')) {
                array_shift($segments);
            }
            $path = mb_strtoupper(implode(' > ', $segments));
            $energy = false;
            if ($scheme === 'Science Keywords') {
                foreach (['EARTH SCIENCE > SOLID EARTH > GEOTHERMAL DYNAMICS > GEOTHERMAL ENERGY', 'EARTH SCIENCE > HUMAN DIMENSIONS > ECONOMIC RESOURCES > ENERGY PRODUCTION/USE'] as $prefix) {
                    $energy = $energy || $path === $prefix || str_starts_with($path, $prefix.' > ');
                }
            } elseif (str_contains(mb_strtolower($scheme ?? ''), 'gemet')) {
                $energy = count($segments) > 1 && mb_strtolower($segments[0]) === 'energy';
            }
            if ($energy) {
                $themes[] = 'ENER';
                break;
            }
        }

        return $themes;
    }
}
