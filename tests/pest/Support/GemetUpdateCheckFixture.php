<?php

declare(strict_types=1);

namespace Tests\Support;

use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

final class GemetUpdateCheckFixture
{
    public const GROUP = 'http://www.eionet.europa.eu/gemet/group/';

    public static function entity(string $uri): array
    {
        return ['uri' => $uri, 'preferredLabel' => ['string' => 'Example', 'language' => 'en']];
    }

    public static function response(Request $request, int $groups = 2): PromiseInterface
    {
        if (str_contains($request->url(), 'getTopmostConcepts')) {
            if (str_contains($request['thesaurus_uri'], 'supergroup')) {
                return Http::response([self::entity('http://www.eionet.europa.eu/gemet/supergroup/1')]);
            }

            return Http::response(array_map(
                fn (int $number): array => self::entity(self::GROUP.$number),
                range(96, 95 + $groups),
            ));
        }

        // Deliberately assigned to every group: the hierarchy counts assignments.
        return Http::response([self::entity('http://www.eionet.europa.eu/gemet/concept/1')]);
    }
}
