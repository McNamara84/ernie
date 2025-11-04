<?php

namespace App\Enums;

/**
 * User role enumeration
 *
 * Defines the hierarchy of user roles in the ERNIE system:
 * - ADMIN: Full system access, can promote users to any role
 * - GROUP_LEADER: Can manage users but cannot promote to GROUP_LEADER or ADMIN
 * - CURATOR: Can register production DOIs and manage resources
 * - BEGINNER: Limited to test DOI registration only
 */
enum UserRole: string
{
    case ADMIN = 'admin';
    case GROUP_LEADER = 'group_leader';
    case CURATOR = 'curator';
    case BEGINNER = 'beginner';

    /**
     * Check if this role can manage users (access user management page)
     */
    public function canManageUsers(): bool
    {
        return match ($this) {
            self::ADMIN, self::GROUP_LEADER => true,
            self::CURATOR, self::BEGINNER => false,
        };
    }

    /**
     * Check if this role can promote users to GROUP_LEADER
     */
    public function canPromoteToGroupLeader(): bool
    {
        return $this === self::ADMIN;
    }

    /**
     * Check if this role can register production DOIs
     * Beginners are restricted to test DOIs only
     */
    public function canRegisterProductionDoi(): bool
    {
        return match ($this) {
            self::ADMIN, self::GROUP_LEADER, self::CURATOR => true,
            self::BEGINNER => false,
        };
    }

    /**
     * Get all roles that this role can promote users to
     *
     * @return array<self>
     */
    public function getPromotableRoles(): array
    {
        return match ($this) {
            self::ADMIN => [self::ADMIN, self::GROUP_LEADER, self::CURATOR, self::BEGINNER],
            self::GROUP_LEADER => [self::CURATOR, self::BEGINNER],
            self::CURATOR, self::BEGINNER => [],
        };
    }

    /**
     * Get human-readable label for the role
     */
    public function label(): string
    {
        return match ($this) {
            self::ADMIN => 'Admin',
            self::GROUP_LEADER => 'Group Leader',
            self::CURATOR => 'Curator',
            self::BEGINNER => 'Beginner',
        };
    }

    /**
     * Get color variant for UI badges
     */
    public function colorVariant(): string
    {
        return match ($this) {
            self::ADMIN => 'purple',
            self::GROUP_LEADER => 'blue',
            self::CURATOR => 'green',
            self::BEGINNER => 'gray',
        };
    }
}
