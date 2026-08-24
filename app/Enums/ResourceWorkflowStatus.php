<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Explicit workflow state for legacy resources whose source state cannot be
 * derived reliably from ERNIE's current metadata completeness rules.
 */
enum ResourceWorkflowStatus: string
{
    case DRAFT = 'draft';
    case REVIEW = 'review';
}
