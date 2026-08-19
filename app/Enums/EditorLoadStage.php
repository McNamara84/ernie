<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Monotonic server-side milestones for loading an existing resource in the editor.
 *
 * Server work intentionally stops at 75%; the remaining progress is reserved for
 * the editor vocabularies that are fetched by the browser after Inertia renders.
 */
enum EditorLoadStage: string
{
    case INITIALIZED = 'initialized';
    case COMMON_PROPS_LOADED = 'common_props_loaded';
    case RESOURCE_LOADED = 'resource_loaded';
    case CONTENT_RELATIONS_LOADED = 'content_relations_loaded';
    case PEOPLE_RELATIONS_LOADED = 'people_relations_loaded';
    case SUPPLEMENTAL_RELATIONS_LOADED = 'supplemental_relations_loaded';
    case PEOPLE_TRANSFORMED = 'people_transformed';
    case IDENTIFICATION_TRANSFORMED = 'identification_transformed';
    case CONTENT_TRANSFORMED = 'content_transformed';
    case RELATED_METADATA_TRANSFORMED = 'related_metadata_transformed';
    case SERVER_READY = 'server_ready';

    public function progress(): int
    {
        return match ($this) {
            self::INITIALIZED => 0,
            self::COMMON_PROPS_LOADED => 5,
            self::RESOURCE_LOADED => 10,
            self::CONTENT_RELATIONS_LOADED => 25,
            self::PEOPLE_RELATIONS_LOADED => 40,
            self::SUPPLEMENTAL_RELATIONS_LOADED => 55,
            self::PEOPLE_TRANSFORMED => 60,
            self::IDENTIFICATION_TRANSFORMED => 65,
            self::CONTENT_TRANSFORMED => 70,
            self::RELATED_METADATA_TRANSFORMED => 74,
            self::SERVER_READY => 75,
        };
    }
}
