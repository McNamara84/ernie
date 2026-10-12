<?php

declare(strict_types=1);

use App\Models\RelatedItem;
use Illuminate\Support\Facades\Schema;

uses()->group('database', 'mysql-sensitive');

test('adds nullable relation information without rewriting existing related items and supports rollback', function () {
    $item = RelatedItem::factory()->create(['publisher' => 'Existing publisher']);
    expect($item->relation_type_information)->toBeNull();
    $migration = require database_path('migrations/2026_10_09_000001_add_relation_type_information_to_related_items.php');
    $migration->down();
    try {
        expect(Schema::hasColumn('related_items', 'relation_type_information'))->toBeFalse();
        expect(RelatedItem::findOrFail($item->id)->publisher)->toBe('Existing publisher');
    } finally {
        $migration->up();
    }
    expect(Schema::hasColumn('related_items', 'relation_type_information'))->toBeTrue();
    $item->refresh();
    expect($item->publisher)->toBe('Existing publisher')
        ->and($item->relation_type_information)->toBeNull();
    $information = str_repeat('Forschungsbeobachtungen – Methoden & Ergebnisse. ', 20);
    $item->update(['relation_type_information' => $information]);
    expect($item->fresh()->relation_type_information)->toBe($information);
});
