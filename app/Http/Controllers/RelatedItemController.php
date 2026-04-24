<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\RelatedItem\StoreRelatedItemRequest;
use App\Http\Requests\RelatedItem\UpdateRelatedItemRequest;
use App\Models\RelatedItem;
use App\Models\Resource;
use App\Services\Citations\RelatedItemStorageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RelatedItemController extends Controller
{
    public function __construct(private readonly RelatedItemStorageService $storage) {}

    public function index(Request $request, Resource $resource): JsonResponse
    {
        $this->authorizeAccess($request, $resource);

        $resource->load([
            'relatedItems.relationType',
            'relatedItems.titles',
            'relatedItems.creators.affiliations',
            'relatedItems.contributors.affiliations',
        ]);

        return response()->json([
            'data' => $resource->relatedItems->map(fn (RelatedItem $item) => $this->present($item))->all(),
        ]);
    }

    public function store(StoreRelatedItemRequest $request, Resource $resource): JsonResponse
    {
        $this->authorizeAccess($request, $resource);

        $item = $this->storage->create($resource, $request->validated());

        return response()->json(['data' => $this->present($item)], 201);
    }

    public function update(UpdateRelatedItemRequest $request, Resource $resource, RelatedItem $relatedItem): JsonResponse
    {
        $this->authorizeAccess($request, $resource);
        abort_unless($relatedItem->resource_id === $resource->id, 404);

        $item = $this->storage->update($relatedItem, $request->validated());

        return response()->json(['data' => $this->present($item)]);
    }

    public function destroy(Request $request, Resource $resource, RelatedItem $relatedItem): JsonResponse
    {
        $this->authorizeAccess($request, $resource);
        abort_unless($relatedItem->resource_id === $resource->id, 404);

        $this->storage->delete($relatedItem);

        return response()->json(null, 204);
    }

    public function reorder(Request $request, Resource $resource): JsonResponse
    {
        $this->authorizeAccess($request, $resource);

        $validated = $request->validate([
            'order' => ['required', 'array'],
            'order.*.id' => ['required', 'integer'],
            'order.*.position' => ['required', 'integer', 'min:0'],
        ]);

        $this->storage->reorder($resource, $validated['order']);

        return response()->json(null, 204);
    }

    private function authorizeAccess(Request $request, Resource $resource): void
    {
        $user = $request->user();
        abort_unless($user !== null && $user->can('update', $resource), 403);
    }

    /**
     * @return array<string, mixed>
     */
    private function present(RelatedItem $item): array
    {
        $item->loadMissing([
            'titles',
            'creators.affiliations',
            'contributors.affiliations',
            'relationType',
        ]);

        return [
            'id' => $item->id,
            'relatedItemType' => $item->related_item_type,
            'relationTypeId' => $item->relation_type_id,
            'relationType' => $item->relationType->slug,
            'publicationYear' => $item->publication_year,
            'volume' => $item->volume,
            'issue' => $item->issue,
            'number' => $item->number,
            'numberType' => $item->number_type,
            'firstPage' => $item->first_page,
            'lastPage' => $item->last_page,
            'publisher' => $item->publisher,
            'edition' => $item->edition,
            'identifier' => $item->identifier,
            'identifierType' => $item->identifier_type,
            'position' => $item->position,
            'titles' => $item->titles->map(fn ($t): array => [
                'id' => $t->id,
                'title' => $t->title,
                'titleType' => $t->title_type,
                'language' => $t->language,
                'position' => $t->position,
            ])->all(),
            'creators' => $item->creators->map(fn ($c): array => $this->presentCreator($c))->all(),
            'contributors' => $item->contributors->map(fn ($c): array => $this->presentContributor($c))->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function presentCreator(\App\Models\RelatedItemCreator $c): array
    {
        return [
            'id' => $c->id,
            'nameType' => $c->name_type,
            'name' => $c->name,
            'givenName' => $c->given_name,
            'familyName' => $c->family_name,
            'nameIdentifier' => $c->name_identifier,
            'nameIdentifierScheme' => $c->name_identifier_scheme,
            'affiliations' => $c->affiliations->map(fn ($a): array => [
                'id' => $a->id,
                'name' => $a->name,
                'affiliationIdentifier' => $a->affiliation_identifier,
                'scheme' => $a->scheme,
            ])->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function presentContributor(\App\Models\RelatedItemContributor $c): array
    {
        return [
            'id' => $c->id,
            'contributorType' => $c->contributor_type,
            'nameType' => $c->name_type,
            'name' => $c->name,
            'givenName' => $c->given_name,
            'familyName' => $c->family_name,
            'nameIdentifier' => $c->name_identifier,
            'nameIdentifierScheme' => $c->name_identifier_scheme,
            'affiliations' => $c->affiliations->map(fn ($a): array => [
                'id' => $a->id,
                'name' => $a->name,
                'affiliationIdentifier' => $a->affiliation_identifier,
                'scheme' => $a->scheme,
            ])->all(),
        ];
    }
}
