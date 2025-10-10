# Related Work Backend Integration

Documentation for the Related Work feature backend implementation in ERNIE.

## Overview

The Related Work feature allows users to:
- Add relationships between resources using DataCite Schema 4.6 relation types
- Import related identifiers from the legacy metaworks database
- Validate and persist related identifiers in the new ERNIE database

## Database Structure

### Table: `resource_related_identifiers`

```sql
CREATE TABLE resource_related_identifiers (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    resource_id BIGINT UNSIGNED NOT NULL,
    identifier VARCHAR(2183) NOT NULL,
    identifier_type VARCHAR(50) NOT NULL,
    relation_type VARCHAR(100) NOT NULL,
    position INT DEFAULT 0,
    related_title VARCHAR(2000) NULL,
    related_metadata JSON NULL,
    created_at TIMESTAMP NULL,
    updated_at TIMESTAMP NULL,
    FOREIGN KEY (resource_id) REFERENCES resources(id) ON DELETE CASCADE,
    INDEX idx_resource_id (resource_id),
    INDEX idx_relation_type (relation_type)
);
```

**Fields:**
- `identifier`: The DOI, URL, Handle, or other identifier (max 2183 chars as per DataCite)
- `identifier_type`: Type of identifier (DOI, URL, Handle, etc.)
- `relation_type`: DataCite relation type (Cites, References, etc.)
- `position`: Sort order (0-based index)
- `related_title`: Optional cached title from API resolution
- `related_metadata`: Optional JSON metadata from DataCite API

## Model

### RelatedIdentifier Model

Location: `app/Models/RelatedIdentifier.php`

**Key Features:**
- Eloquent relationship to `Resource` model
- Constants for all 33 DataCite relation types grouped by category
- Helper method `getOppositeRelationType()` for bidirectional suggestions
- List of most used relation types from metaworks analysis

**Usage:**

```php
// Access related identifiers
$resource = Resource::with('relatedIdentifiers')->find($id);
$relatedWorks = $resource->relatedIdentifiers;

// Create new related identifier
$resource->relatedIdentifiers()->create([
    'identifier' => '10.5194/nhess-15-1463-2015',
    'identifier_type' => 'DOI',
    'relation_type' => 'Cites',
    'position' => 0,
]);
```

## Controller Integration

### ResourceController

**Store Method** (`app/Http/Controllers/ResourceController.php:288`)

Related identifiers are saved as part of the resource creation/update transaction:

```php
// Save related identifiers
if ($isUpdate) {
    $resource->relatedIdentifiers()->delete();
}

$relatedIdentifiers = $validated['relatedIdentifiers'] ?? [];

foreach ($relatedIdentifiers as $index => $relatedIdentifier) {
    if (!empty(trim($relatedIdentifier['identifier']))) {
        $resource->relatedIdentifiers()->create([
            'identifier' => trim($relatedIdentifier['identifier']),
            'identifier_type' => $relatedIdentifier['identifierType'],
            'relation_type' => $relatedIdentifier['relationType'],
            'position' => $index,
        ]);
    }
}
```

**Index Method** (`app/Http/Controllers/ResourceController.php:32`)

Related identifiers are included when loading resources:

```php
->with([
    // ... other relations
    'relatedIdentifiers:id,resource_id,identifier,identifier_type,relation_type,position',
])
```

And transformed for the frontend:

```php
'relatedIdentifiers' => $resource->relatedIdentifiers
    ->sortBy('position')
    ->map(static function (\App\Models\RelatedIdentifier $relatedIdentifier): array {
        return [
            'identifier' => $relatedIdentifier->identifier,
            'identifierType' => $relatedIdentifier->identifier_type,
            'relationType' => $relatedIdentifier->relation_type,
            'position' => $relatedIdentifier->position,
        ];
    })
    ->values()
    ->all(),
```

### OldDatasetController

**getRelatedIdentifiers Method** (`app/Http/Controllers/OldDatasetController.php`)

Fetches related identifiers from the legacy metaworks database:

```php
public function getRelatedIdentifiers(Request $request, int $id)
{
    $dataset = OldDataset::find($id);
    $relatedIdentifiers = $dataset->getRelatedIdentifiers();
    
    return response()->json([
        'relatedIdentifiers' => $relatedIdentifiers,
    ]);
}
```

**Route:**
```
GET /old-datasets/{id}/related-identifiers
```

## Validation

### StoreResourceRequest

Location: `app/Http/Requests/StoreResourceRequest.php`

**Validation Rules:**

```php
'relatedIdentifiers' => ['nullable', 'array'],
'relatedIdentifiers.*.identifier' => ['required', 'string', 'max:2183'],
'relatedIdentifiers.*.identifierType' => [
    'required',
    'string',
    Rule::in(['DOI', 'URL', 'Handle', 'IGSN', 'URN', 'ISBN', 'ISSN', 
              'PURL', 'ARK', 'arXiv', 'bibcode', 'EAN13', 'EISSN', 
              'ISTC', 'LISSN', 'LSID', 'PMID', 'UPC', 'w3id']),
],
'relatedIdentifiers.*.relationType' => [
    'required',
    'string',
    Rule::in([
        'Cites', 'IsCitedBy', 'References', 'IsReferencedBy',
        'IsSupplementTo', 'IsSupplementedBy', 'IsContinuedBy', 'Continues',
        'Describes', 'IsDescribedBy', 'HasMetadata', 'IsMetadataFor',
        'HasVersion', 'IsVersionOf', 'IsNewVersionOf', 'IsPreviousVersionOf',
        'IsPartOf', 'HasPart', 'IsPublishedIn', 'IsDocumentedBy', 'Documents',
        'IsCompiledBy', 'Compiles', 'IsVariantFormOf', 'IsOriginalFormOf',
        'IsIdenticalTo', 'IsReviewedBy', 'Reviews', 'IsDerivedFrom', 
        'IsSourceOf', 'IsRequiredBy', 'Requires',
    ]),
],
```

## Routes

### Curation Route

The curation page accepts `relatedWorks` as a query parameter:

```php
Route::get('curation', function (\Illuminate\Http\Request $request) {
    return Inertia::render('curation', [
        // ... other props
        'relatedWorks' => $request->query('relatedWorks', []),
    ]);
})->name('curation');
```

### Resource Store Route

```php
Route::post('curation/resources', [ResourceController::class, 'store'])
    ->name('curation.resources.store');
```

### Legacy Import Route

```php
Route::get('old-datasets/{id}/related-identifiers', [OldDatasetController::class, 'getRelatedIdentifiers'])
    ->name('old-datasets.related-identifiers');
```

## Data Flow

### Creating/Updating a Resource

1. User fills out Related Work form (Quick/Advanced/CSV mode)
2. Frontend sends data to `POST /curation/resources`
3. `StoreResourceRequest` validates the data
4. `ResourceController::store()` creates/updates resource in transaction
5. Old related identifiers are deleted (if update)
6. New related identifiers are created with position indices
7. Response includes the saved related identifiers

### Importing from Legacy Database

1. User views old dataset on `/old-datasets`
2. Clicks "Import" button for a dataset
3. Frontend fetches data from `GET /old-datasets/{id}/related-identifiers`
4. `OldDatasetController::getRelatedIdentifiers()` queries metaworks DB
5. Data is transformed to match new schema
6. User is redirected to `/curation` with pre-filled data
7. User can review and save to new database

## Legacy Database Schema

The metaworks database stores related identifiers in:

**Table:** `resource_rel_id`

**Columns:**
- `resource_id` (BIGINT)
- `identifier` (VARCHAR)
- `identifier_type` (VARCHAR)
- `relation_type` (VARCHAR)
- `position` (INT)

## Performance Considerations

### Eager Loading

Always eager load related identifiers when displaying resources:

```php
Resource::with('relatedIdentifiers')->get();
```

### Indexing

The migration includes indices for:
- `resource_id` (foreign key, frequent joins)
- `relation_type` (filtering by type)

### Position Handling

Position is managed automatically:
- On creation: Uses array index from frontend
- On update: Old entries deleted, new ones created with new positions
- On removal: Frontend reindexes remaining items before saving

## Error Handling

### Validation Errors

Invalid identifier types or relation types return 422 Unprocessable Entity:

```json
{
  "message": "The given data was invalid.",
  "errors": {
    "relatedIdentifiers.0.identifierType": [
      "The selected identifier type is invalid."
    ]
  }
}
```

### Database Errors

Connection or query failures are logged and return 500 Internal Server Error.

### Legacy Database Errors

Metaworks connection failures return detailed debug info:

```json
{
  "error": "Failed to load related identifiers from legacy database.",
  "debug": {
    "connection": "metaworks",
    "driver": "mysql",
    "hosts": ["localhost"],
    "database": "metaworks"
  }
}
```

## Testing

### Manual Testing Checklist

- [ ] Create resource with related identifiers
- [ ] Update resource and modify related identifiers
- [ ] Delete all related identifiers from resource
- [ ] Import from legacy database
- [ ] Validate CSV bulk import
- [ ] Test all 33 relation types
- [ ] Test all 19 identifier types
- [ ] Verify position ordering
- [ ] Check cascade delete (delete resource → related identifiers also deleted)

### Example Test Data

```json
{
  "relatedIdentifiers": [
    {
      "identifier": "10.5194/nhess-15-1463-2015",
      "identifierType": "DOI",
      "relationType": "Cites"
    },
    {
      "identifier": "https://github.com/example/repo",
      "identifierType": "URL",
      "relationType": "IsDocumentedBy"
    }
  ]
}
```

## Future Enhancements

### Planned Features

1. **DataCite API Metadata Caching**
   - Store resolved title and metadata in `related_title` and `related_metadata` fields
   - Display in UI without re-querying API

2. **XML Import**
   - Parse DataCite XML Schema 4.6 `<relatedIdentifiers>` elements
   - Bulk import from XML files

3. **Duplicate Detection**
   - Check for existing related identifiers before saving
   - Warn user about potential duplicates

4. **Relationship Graph**
   - Visualize connections between resources
   - Network diagram of citations/references

### Migration Path

When implementing new features:
1. Update migration if database schema changes
2. Update `RelatedIdentifier` model with new methods
3. Update validation rules in `StoreResourceRequest`
4. Update frontend components
5. Update this documentation

## References

- DataCite Metadata Schema 4.6: https://schema.datacite.org/meta/kernel-4.6/
- DataCite REST API: https://support.datacite.org/docs/api
- Laravel Eloquent Relationships: https://laravel.com/docs/eloquent-relationships
