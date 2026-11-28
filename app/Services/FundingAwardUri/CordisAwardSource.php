<?php

declare(strict_types=1);

namespace App\Services\FundingAwardUri;

final class CordisAwardSource extends CachedAwardSource
{
    public function lookup(string $awardNumber, FunderIdentity $funder): AwardSourceResult
    {
        if ($funder->cordisProgrammes() === []) {
            return new AwardSourceResult;
        }

        return $this->cached('cordis', $awardNumber, $funder, function () use ($awardNumber): AwardSourceResult {
            if (! ctype_digit($awardNumber)) {
                return new AwardSourceResult;
            }
            $literal = json_encode($awardNumber, JSON_THROW_ON_ERROR);
            $prefix = 'PREFIX eurio: <http://data.europa.eu/s66#> ';
            $data = $this->http->get('cordis', ['query' => $prefix.'SELECT DISTINCT ?project WHERE { ?project a eurio:Project; eurio:identifier '.$literal.' . } LIMIT 2']);
            $rows = $this->bindings($data);
            if ($rows === null) {
                return new AwardSourceResult(error: 'invalid_source_response');
            }
            if (count($rows) > 1) {
                return new AwardSourceResult(error: 'ambiguous_award');
            }
            if ($rows === []) {
                return new AwardSourceResult;
            }
            $project = $rows[0]['project']['value'] ?? '';
            if (! is_string($project) || ! preg_match('~^http://data\.europa\.eu/s66/resource/projects/[a-f0-9-]+$~D', $project)) {
                return new AwardSourceResult(error: 'invalid_source_response');
            }
            $query = $prefix.'SELECT DISTINCT ?id ?title ?grant ?programme ?code WHERE { <'.$project.'> eurio:identifier ?id . '
                .'OPTIONAL { <'.$project.'> eurio:title ?title } OPTIONAL { <'.$project.'> eurio:isFundedBy ?grant . '
                .'OPTIONAL { ?grant eurio:hasFundingSchemeProgramme ?programme . ?programme eurio:code ?code } } } LIMIT 301';
            $rows = $this->bindings($this->http->get('cordis', ['query' => $query]));
            if ($rows === null || $rows === [] || count($rows) >= 301) {
                return new AwardSourceResult(error: 'query_incomplete');
            }
            $titles = [];
            $programmes = [];
            $grants = [];
            foreach ($rows as $row) {
                if (($row['id']['value'] ?? null) !== $awardNumber) {
                    return new AwardSourceResult(error: 'award_number_mismatch');
                }
                if (($title = AwardMetadata::text($row['title']['value'] ?? null)) !== '') {
                    $titles[] = $title;
                }
                if (($grant = AwardMetadata::text($row['grant']['value'] ?? null)) !== '') {
                    $grants[] = $grant;
                }
                if (($code = AwardMetadata::text($row['code']['value'] ?? null)) !== '') {
                    $programmes[] = $code;
                }
            }
            if (count(array_unique($grants)) !== 1 || $programmes === []) {
                return new AwardSourceResult(error: 'insufficient_programme_evidence');
            }
            $uri = 'https://cordis.europa.eu/project/id/'.$awardNumber;

            return new AwardSourceResult([new AwardCandidate('cordis', $project, $awardNumber, $uri, [],
                array_values(array_unique($titles)), array_values(array_unique($programmes)), now()->toIso8601String(), $uri)]);
        });
    }

    /** @param array<string, mixed> $data
     * @return list<array<string, array<string, mixed>>>|null
     */
    private function bindings(array $data): ?array
    {
        $rows = $data['results']['bindings'] ?? null;
        if (! is_array($rows) || ! array_is_list($rows)) {
            return null;
        }
        foreach ($rows as $row) {
            if (! is_array($row)) {
                return null;
            }
            foreach ($row as $value) {
                if (! is_array($value)) {
                    return null;
                }
            }
        }

        return $rows;
    }
}
