<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Enums\UserRole;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

/**
 * @property int $id
 * @property string $name
 * @property string $email
 * @property string|null $password
 * @property string|null $remember_token
 * @property UserRole $role
 * @property bool $is_active
 * @property string|null $font_size_preference
 * @property \Illuminate\Support\Carbon|null $email_verified_at
 * @property \Illuminate\Support\Carbon|null $deactivated_at
 * @property int|null $deactivated_by
 * @property \Illuminate\Support\Carbon $created_at
 * @property \Illuminate\Support\Carbon $updated_at
 */
class User extends Authenticatable
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'font_size_preference',
        'role',
        'is_active',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'role' => UserRole::class,
            'is_active' => 'boolean',
            'deactivated_at' => 'datetime',
        ];
    }

    /**
     * Get the resources created by this user.
     *
     * @return HasMany<\App\Models\Resource, static>
     */
    public function createdResources(): HasMany
    {
        /** @var HasMany<\App\Models\Resource, static> $relation */
        $relation = $this->hasMany(Resource::class, 'created_by_user_id');

        return $relation;
    }

    /**
     * Get the resources updated by this user.
     *
     * @return HasMany<\App\Models\Resource, static>
     */
    public function updatedResources(): HasMany
    {
        /** @var HasMany<\App\Models\Resource, static> $relation */
        $relation = $this->hasMany(Resource::class, 'updated_by_user_id');

        return $relation;
    }

    /**
     * Get the user who deactivated this user.
     *
     * @return BelongsTo<User, static>
     */
    public function deactivatedBy(): BelongsTo
    {
        /** @var BelongsTo<User, static> $relation */
        $relation = $this->belongsTo(User::class, 'deactivated_by');

        return $relation;
    }

    /**
     * Get the users deactivated by this user.
     *
     * @return HasMany<User, static>
     */
    public function deactivatedUsers(): HasMany
    {
        /** @var HasMany<User, static> $relation */
        $relation = $this->hasMany(User::class, 'deactivated_by');

        return $relation;
    }

    /**
     * Scope a query to only include active users.
     *
     * @param  \Illuminate\Database\Eloquent\Builder<User>  $query
     * @return \Illuminate\Database\Eloquent\Builder<User>
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Scope a query to only include inactive users.
     *
     * @param  \Illuminate\Database\Eloquent\Builder<User>  $query
     * @return \Illuminate\Database\Eloquent\Builder<User>
     */
    public function scopeInactive($query)
    {
        return $query->where('is_active', false);
    }

    /**
     * Scope a query to filter by role.
     *
     * @param  \Illuminate\Database\Eloquent\Builder<User>  $query
     * @return \Illuminate\Database\Eloquent\Builder<User>
     */
    public function scopeRole($query, UserRole $role)
    {
        return $query->where('role', $role->value);
    }

    /**
     * Check if user is an admin.
     */
    public function isAdmin(): bool
    {
        return $this->role === UserRole::ADMIN;
    }

    /**
     * Check if user is a group leader.
     */
    public function isGroupLeader(): bool
    {
        return $this->role === UserRole::GROUP_LEADER;
    }

    /**
     * Check if user is a curator.
     */
    public function isCurator(): bool
    {
        return $this->role === UserRole::CURATOR;
    }

    /**
     * Check if user is a beginner.
     */
    public function isBeginner(): bool
    {
        return $this->role === UserRole::BEGINNER;
    }

    /**
     * Check if user can manage other users.
     */
    public function canManageUsers(): bool
    {
        return $this->role->canManageUsers();
    }

    /**
     * Check if user can promote others to group leader.
     */
    public function canPromoteToGroupLeader(): bool
    {
        return $this->role->canPromoteToGroupLeader();
    }

    /**
     * Check if user can register production DOIs.
     */
    public function canRegisterProductionDoi(): bool
    {
        return $this->role->canRegisterProductionDoi();
    }
}
