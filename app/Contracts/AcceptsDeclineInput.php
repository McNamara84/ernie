<?php

declare(strict_types=1);

namespace App\Contracts;

use App\Models\User;

interface AcceptsDeclineInput
{
    /** @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function declineSuggestionWithInput(int $id, User $user, ?string $reason, array $input): array;
}
