<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * DismissedOrcid Model
 *
 * Stores ORCID suggestions that curators have explicitly declined,
 * preventing them from being re-suggested for the same person.
 *
 * @property int $id
 * @property int $person_id
 * @property string $orcid
 * @property int|null $dismissed_by
 * @property string|null $reason
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Person $person
 * @property-read User|null $dismissedBy
 */
#[Fillable([
    'person_id',
    'orcid',
    'dismissed_by',
    'reason',
])]
class DismissedOrcid extends Model
{
    /** @return BelongsTo<Person, static> */
    public function person(): BelongsTo
    {
        /** @var BelongsTo<Person, static> $relation */
        $relation = $this->belongsTo(Person::class);

        return $relation;
    }

    /** @return BelongsTo<User, static> */
    public function dismissedBy(): BelongsTo
    {
        /** @var BelongsTo<User, static> $relation */
        $relation = $this->belongsTo(User::class, 'dismissed_by');

        return $relation;
    }
}
