<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\LandingPage;
use App\Models\LandingPageLink;
use Illuminate\Validation\ValidationException;

/** Preserve server-owned content descriptors when links are edited or previewed. */
final class LandingPageLinkSyncService
{
    /** @param list<array<string, mixed>> $rows
     * @return list<array<string, mixed>>
     */
    public function preview(?LandingPage $landingPage, array $rows): array
    {
        $existing = $landingPage?->links()->get();
        $used = [];
        $result = [];
        foreach ($rows as $index => $row) {
            $kind = $row['kind'] ?? LandingPageLink::KIND_RELATED;
            $link = isset($row['id']) ? $existing?->find((int) $row['id']) : null;
            if (isset($row['id']) && $link === null) {
                throw ValidationException::withMessages(["links.{$index}.id" => 'This link no longer belongs to this landing page.']);
            }
            if ($link === null) {
                // Older clients did not send IDs. Only an unambiguous URL match is safe.
                $matches = $existing?->where('url', $row['url'])->where('kind', $kind);
                $link = $matches?->count() === 1 ? $matches->first() : null;
            }
            if ($link !== null && isset($used[$link->id])) {
                throw ValidationException::withMessages(["links.{$index}.id" => 'A link may only appear once.']);
            }
            if ($link !== null) {
                $used[$link->id] = true;
            }
            $result[] = [
                ...($link !== null ? ['id' => $link->id] : []),
                'url' => $row['url'], 'label' => $row['label'], 'kind' => $kind, 'position' => $row['position'],
                'format_id' => $kind === LandingPageLink::KIND_DOWNLOAD ? $link?->format_id : null,
                'size_id' => $kind === LandingPageLink::KIND_DOWNLOAD ? $link?->size_id : null,
            ];
        }

        return $result;
    }

    /** @param list<array<string, mixed>> $rows */
    public function sync(LandingPage $landingPage, array $rows): void
    {
        $rows = $this->preview($landingPage, $rows);
        // Make room for swaps under the unique (landing_page_id, position) index.
        $landingPage->links()->increment('position', 10);
        $retained = [];
        foreach ($rows as $row) {
            $link = isset($row['id']) ? $landingPage->links()->findOrFail((int) $row['id']) : new LandingPageLink(['landing_page_id' => $landingPage->id]);
            unset($row['id']);
            $link->fill($row)->save();
            $retained[] = $link->id;
        }
        foreach ($landingPage->links()->whereNotIn('id', $retained)->get() as $link) {
            $link->delete();
        }
        $landingPage->unsetRelation('links');
    }
}
