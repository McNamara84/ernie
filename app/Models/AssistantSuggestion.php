<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * AssistantSuggestion Model
 *
 * Generic suggestion storage for new assistant modules. Each row represents
 * a single suggestion from an assistant module (identified by assistant_id).
 *
 * Existing assistants (ORCID, ROR, Relations) use their own dedicated tables.
 * Only new student-created assistants (e.g. SPDX License) use this table.
 *
 * @property int $id
 * @property string $assistant_id
 * @property int $resource_id
 * @property string $target_type
 * @property int $target_id
 * @property string $suggested_value
 * @property string $suggested_label
 * @property float|null $similarity_score
 * @property array<string, mixed>|null $metadata
 * @property Carbon $discovered_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Resource $resource
 */
#[Fillable([
    'assistant_id',
    'resource_id',
    'target_type',
    'target_id',
    'suggested_value',
    'suggested_label',
    'similarity_score',
    'metadata',
    'discovered_at',
])]
class AssistantSuggestion extends Model
{
    /** @var array<string, string> */
    protected $casts = [
        'discovered_at' => 'datetime',
        'similarity_score' => 'float',
        'metadata' => 'array',
    ];

    /** @return BelongsTo<Resource, static> */
    public function resource(): BelongsTo
    {
        /** @var BelongsTo<Resource, static> $relation */
        $relation = $this->belongsTo(Resource::class);

        return $relation;
    }
}
