<?php

declare(strict_types=1);

use App\Services\DatabaseDumps\DatabaseDumpProcessResult;

covers(DatabaseDumpProcessResult::class);

it('reports successful dump process results by exit code', function (): void {
    expect(new DatabaseDumpProcessResult(0)->successful())->toBeTrue()
        ->and(new DatabaseDumpProcessResult(1, 'failed')->successful())->toBeFalse();
});
