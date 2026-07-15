<?php

declare(strict_types=1);

use App\Http\Requests\BatchRelationsRequest;

it('authorizes relation batch requests', function (): void {
    expect((new BatchRelationsRequest())->authorize())->toBeTrue();
});

it('defines validation rules for relation batch requests', function (): void {
    expect((new BatchRelationsRequest())->rules())->toBe([
        'suggestion_ids' => ['required', 'array', 'min:1'],
        'suggestion_ids.*' => ['integer', 'distinct'],
        'reason' => ['nullable', 'string', 'max:1000'],
    ]);
});