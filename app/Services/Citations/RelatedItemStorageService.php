<?php

declare(strict_types=1);

namespace App\Services\Citations;

use App\Models\RelatedItem;
use App\Models\Resource;
use Illuminate\Support\Facades\DB;

/**
 * Creates, updates, and deletes RelatedItem aggregates (item + titles +
 * creators + contributors + affiliations) transactionally.
 */
class RelatedItemStorageService
{
    /**
     * @param array<string, mixed> $data
     */
    public function create(Resource $resource, array $data): RelatedItem
    {
        return DB::transaction(function () use ($resource, $data) {
            $position = $data['position']
                ?? ($resource->relatedItems()->max('position') ?? -1) + 1;

            /** @var RelatedItem $item */
            $item = $resource->relatedItems()->create([
                'related_item_type' => $data['related_item_type'],
                'relation_type_id' => (int) $data['relation_type_id'],
                'publication_year' => $data['publication_year'] ?? null,
                'volume' => $data['volume'] ?? null,
                'issue' => $data['issue'] ?? null,
                'number' => $data['number'] ?? null,
                'number_type' => $data['number_type'] ?? null,
                'first_page' => $data['first_page'] ?? null,
                'last_page' => $data['last_page'] ?? null,
                'publisher' => $data['publisher'] ?? null,
                'edition' => $data['edition'] ?? null,
                'identifier' => $data['identifier'] ?? null,
                'identifier_type' => $data['identifier_type'] ?? null,
                'related_metadata_scheme' => $data['related_metadata_scheme'] ?? null,
                'scheme_uri' => $data['scheme_uri'] ?? null,
                'scheme_type' => $data['scheme_type'] ?? null,
                'position' => (int) $position,
            ]);

            $this->syncTitles($item, $data['titles'] ?? []);
            $this->syncCreators($item, $data['creators'] ?? []);
            $this->syncContributors($item, $data['contributors'] ?? []);

            $refreshed = $item->fresh([
                'titles',
                'creators.affiliations',
                'contributors.affiliations',
                'relationType',
            ]);
            assert($refreshed instanceof RelatedItem);

            return $refreshed;
        });
    }

    /**
     * @param array<string, mixed> $data
     */
    public function update(RelatedItem $item, array $data): RelatedItem
    {
        return DB::transaction(function () use ($item, $data) {
            $item->update([
                'related_item_type' => $data['related_item_type'],
                'relation_type_id' => (int) $data['relation_type_id'],
                'publication_year' => $data['publication_year'] ?? null,
                'volume' => $data['volume'] ?? null,
                'issue' => $data['issue'] ?? null,
                'number' => $data['number'] ?? null,
                'number_type' => $data['number_type'] ?? null,
                'first_page' => $data['first_page'] ?? null,
                'last_page' => $data['last_page'] ?? null,
                'publisher' => $data['publisher'] ?? null,
                'edition' => $data['edition'] ?? null,
                'identifier' => $data['identifier'] ?? null,
                'identifier_type' => $data['identifier_type'] ?? null,
                'related_metadata_scheme' => $data['related_metadata_scheme'] ?? null,
                'scheme_uri' => $data['scheme_uri'] ?? null,
                'scheme_type' => $data['scheme_type'] ?? null,
            ]);

            // Simple replace strategy: remove old children, insert new.
            $item->titles()->delete();
            $item->creators()->delete(); // cascades to affiliations
            $item->contributors()->delete();

            $this->syncTitles($item, $data['titles'] ?? []);
            $this->syncCreators($item, $data['creators'] ?? []);
            $this->syncContributors($item, $data['contributors'] ?? []);

            $refreshed = $item->fresh([
                'titles',
                'creators.affiliations',
                'contributors.affiliations',
                'relationType',
            ]);
            assert($refreshed instanceof RelatedItem);

            return $refreshed;
        });
    }

    public function delete(RelatedItem $item): void
    {
        $item->delete();
    }

    /**
     * @param array<int, array{id: int, position: int}> $order
     */
    public function reorder(Resource $resource, array $order): void
    {
        if ($order === []) {
            return;
        }

        // Bulk update via a single CASE/WHEN UPDATE so we issue one query
        // regardless of list size, instead of one UPDATE per row. Both id and
        // position are explicitly cast to int, so embedding them directly into
        // the raw expression is safe (no SQL injection surface).
        DB::transaction(function () use ($resource, $order): void {
            $ids = [];
            $cases = [];
            foreach ($order as $entry) {
                $id = (int) $entry['id'];
                $position = (int) $entry['position'];
                $ids[] = $id;
                $cases[] = sprintf('WHEN %d THEN %d', $id, $position);
            }

            $resource->relatedItems()
                ->whereIn('id', $ids)
                ->update([
                    'position' => DB::raw(
                        // Both id and position are explicitly cast to int above,
                        // so the resulting CASE expression contains no user input.
                        // @phpstan-ignore argument.type
                        'CASE id ' . implode(' ', $cases) . ' ELSE position END'
                    ),
                ]);
        });
    }

    /**
     * @param array<int, array<string, mixed>> $titles
     */
    private function syncTitles(RelatedItem $item, array $titles): void
    {
        foreach (array_values($titles) as $index => $title) {
            $item->titles()->create([
                'title' => (string) $title['title'],
                'title_type' => (string) $title['title_type'],
                'language' => $title['language'] ?? null,
                'position' => $title['position'] ?? $index,
            ]);
        }
    }

    /**
     * @param array<int, array<string, mixed>> $creators
     */
    private function syncCreators(RelatedItem $item, array $creators): void
    {
        foreach (array_values($creators) as $index => $creator) {
            $model = $item->creators()->create([
                'name_type' => $creator['name_type'] ?? 'Personal',
                'name' => (string) $creator['name'],
                'given_name' => $creator['given_name'] ?? null,
                'family_name' => $creator['family_name'] ?? null,
                'name_identifier' => $creator['name_identifier'] ?? null,
                'name_identifier_scheme' => $creator['name_identifier_scheme'] ?? null,
                'scheme_uri' => $creator['scheme_uri'] ?? null,
                'position' => $creator['position'] ?? $index,
            ]);

            foreach (array_values($creator['affiliations'] ?? []) as $affIndex => $aff) {
                $model->affiliations()->create([
                    'name' => (string) $aff['name'],
                    'affiliation_identifier' => $aff['affiliation_identifier'] ?? null,
                    'scheme' => $aff['scheme'] ?? null,
                    'scheme_uri' => $aff['scheme_uri'] ?? null,
                    'position' => $aff['position'] ?? $affIndex,
                ]);
            }
        }
    }

    /**
     * @param array<int, array<string, mixed>> $contributors
     */
    private function syncContributors(RelatedItem $item, array $contributors): void
    {
        foreach (array_values($contributors) as $index => $contributor) {
            $model = $item->contributors()->create([
                'contributor_type' => (string) $contributor['contributor_type'],
                'name_type' => $contributor['name_type'] ?? 'Personal',
                'name' => (string) $contributor['name'],
                'given_name' => $contributor['given_name'] ?? null,
                'family_name' => $contributor['family_name'] ?? null,
                'name_identifier' => $contributor['name_identifier'] ?? null,
                'name_identifier_scheme' => $contributor['name_identifier_scheme'] ?? null,
                'scheme_uri' => $contributor['scheme_uri'] ?? null,
                'position' => $contributor['position'] ?? $index,
            ]);

            foreach (array_values($contributor['affiliations'] ?? []) as $affIndex => $aff) {
                $model->affiliations()->create([
                    'name' => (string) $aff['name'],
                    'affiliation_identifier' => $aff['affiliation_identifier'] ?? null,
                    'scheme' => $aff['scheme'] ?? null,
                    'scheme_uri' => $aff['scheme_uri'] ?? null,
                    'position' => $aff['position'] ?? $affIndex,
                ]);
            }
        }
    }
}
