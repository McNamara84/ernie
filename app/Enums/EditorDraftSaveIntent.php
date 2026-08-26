<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Describes why the editor uses the relaxed draft-validation endpoint.
 *
 * Only an explicit user-initiated draft save changes workflow state. Autosave
 * and landing-page preparation persist partial metadata without reclassifying
 * the resource.
 */
enum EditorDraftSaveIntent: string
{
    case SAVE_DRAFT = 'save-draft';
    case AUTOSAVE = 'autosave';
    case LANDING_PAGE_PREVIEW = 'landing-page-preview';
}
