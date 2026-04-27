<?php

declare(strict_types=1);

namespace App\Http\Requests\RelatedItem;

class UpdateRelatedItemRequest extends StoreRelatedItemRequest
{
    // Shares validation rules with StoreRelatedItemRequest; separate class
    // for policy-based extension if needed.
}
