<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\UserRole;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string $key
 * @property int $version
 * @property string $name
 * @property string|null $description
 * @property string $start_route
 * @property array<int, string> $target_roles
 * @property bool $is_active
 * @property bool $auto_assign
 * @property int|null $created_by
 */
#[Fillable(['key', 'version', 'name', 'description', 'start_route', 'target_roles', 'is_active', 'auto_assign', 'created_by'])]
class GuidedTour extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'target_roles' => 'array',
            'is_active' => 'boolean',
            'auto_assign' => 'boolean',
        ];
    }

    /**
     * @return HasMany<UserGuidedTourAssignment, static>
     */
    public function assignments(): HasMany
    {
        /** @var HasMany<UserGuidedTourAssignment, static> $relation */
        $relation = $this->hasMany(UserGuidedTourAssignment::class);

        return $relation;
    }

    /**
     * @return BelongsTo<User, static>
     */
    public function creator(): BelongsTo
    {
        /** @var BelongsTo<User, static> $relation */
        $relation = $this->belongsTo(User::class, 'created_by');

        return $relation;
    }

    public function targetsRole(UserRole|string $role): bool
    {
        $roleValue = $role instanceof UserRole ? $role->value : $role;

        return in_array($roleValue, $this->target_roles, true);
    }
}
