<?php

declare(strict_types=1);

namespace App\Services\DcatAp;

use App\Models\Resource;
use App\Support\IdentifierUrl;

final class DcatApRightsMapper
{
    /** @return array{license: array{uri: string|null, label: string}|null, statements: list<string>, ambiguous: bool} */
    public function resolve(Resource $resource): array
    {
        $licenses = [];
        $statements = [];
        foreach ($resource->resourceRights->sortBy('id') as $right) {
            $uri = IdentifierUrl::http($right->rights_uri ?? $right->right?->uri);
            $label = trim((string) ($right->rights_text ?? $right->right->name ?? $right->rights_identifier));
            if ($uri !== null && preg_match('~^https?://purl.org/coar/access_right/~i', $uri)) {
                continue;
            }
            if ($label === '' && $uri === null) {
                continue;
            }
            // Different IRIs are not evidence of the same license. Only collapse
            // identical identifiers; unknown aliases remain an editorial ambiguity.
            $key = $uri ?? $label;
            $licenses[$key] = ['uri' => $uri, 'label' => $label];
            $statements[] = $label.($uri === null ? '' : ($label === '' ? '' : ' — ').$uri);
        }

        return [
            'license' => count($licenses) === 1 ? array_values($licenses)[0] : null,
            'statements' => array_values(array_unique($statements)),
            'ambiguous' => count($licenses) > 1,
        ];
    }
}
