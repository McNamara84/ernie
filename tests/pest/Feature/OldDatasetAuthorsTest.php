<?php

use App\Models\OldDataset;
use Illuminate\Support\Facades\DB;

/**
 * Tests für das Laden von Autoren aus alten Datensätzen.
 * Diese Tests verwenden echte Beispieldaten aus der Legacy-Datenbank,
 * die hart kodiert sind und in eine Test-Datenbank geschrieben werden.
 * 
 * Beispieldaten basieren auf echten Einträgen aus der Legacy-Datenbank:
 * - Resource 8: Natalya Mikhailova (ContactPerson + Creator) mit 5 Autoren
 * - Resource 8, Order 10: Shahid Ullah (pointOfContact) mit Email
 * - Resource 1: Uhlemann, Steffi (Fuzzy Matching Test)
 */

beforeEach(function () {
    // Bereinige Testdaten vor jedem Test
    DB::connection('metaworks')->table('affiliation')->whereIn('resourceagent_resource_id', [999998, 999999])->delete();
    DB::connection('metaworks')->table('role')->whereIn('resourceagent_resource_id', [999998, 999999])->delete();
    DB::connection('metaworks')->table('contactinfo')->whereIn('resourceagent_resource_id', [999998, 999999])->delete();
    DB::connection('metaworks')->table('resourceagent')->whereIn('resource_id', [999998, 999999])->delete();
    DB::connection('metaworks')->table('resource')->whereIn('id', [999998, 999999])->delete();
    
    // Erstelle Test-Resource-Einträge
    DB::connection('metaworks')->table('resource')->insert([
        [
            'id' => 999998,
            'publicstatus' => 'released',
            'curator' => 'test',
            'created_at' => now(),
            'updated_at' => now()
        ],
        [
            'id' => 999999,
            'publicstatus' => 'released',
            'curator' => 'test',
            'created_at' => now(),
            'updated_at' => now()
        ],
    ]);
});

afterEach(function () {
    // Bereinige Testdaten nach jedem Test
    DB::connection('metaworks')->table('affiliation')->whereIn('resourceagent_resource_id', [999998, 999999])->delete();
    DB::connection('metaworks')->table('role')->whereIn('resourceagent_resource_id', [999998, 999999])->delete();
    DB::connection('metaworks')->table('contactinfo')->whereIn('resourceagent_resource_id', [999998, 999999])->delete();
    DB::connection('metaworks')->table('resourceagent')->whereIn('resource_id', [999998, 999999])->delete();
    DB::connection('metaworks')->table('resource')->whereIn('id', [999998, 999999])->delete();
});

it('lädt Autoren mit Rollen und Affiliationen (Beispiel: Natalya Mikhailova, Resource 8)', function () {
    $testResourceId = 999998;
    
    // Füge echte Testdaten basierend auf Resource 8 ein
    // Autor 1: Natalya Mikhailova (ContactPerson + Creator mit Affiliation)
    DB::connection('metaworks')->table('resourceagent')->insert([
        'resource_id' => $testResourceId,
        'order' => 1,
        'name' => 'Mikhailova, Natalya',
        'firstname' => 'Natalya',
        'lastname' => 'Mikhailova',
    ]);
    
    DB::connection('metaworks')->table('role')->insert([
        ['resourceagent_resource_id' => $testResourceId, 'resourceagent_order' => 1, 'role' => 'Creator'],
        ['resourceagent_resource_id' => $testResourceId, 'resourceagent_order' => 1, 'role' => 'ContactPerson'],
    ]);
    
    DB::connection('metaworks')->table('affiliation')->insert([
        'resourceagent_resource_id' => $testResourceId,
        'resourceagent_order' => 1,
        'order' => 1,
        'name' => 'Institute of Geophysical Researches, Committee of Atomic Energy of the Republic of Kazakhstan, Almaty, Kazakhstan',
        'identifier' => null,
    ]);
    
    // Autor 2: Poleshko, N.N. (nur Creator, keine Affiliation)
    DB::connection('metaworks')->table('resourceagent')->insert([
        'resource_id' => $testResourceId,
        'order' => 2,
        'name' => 'Poleshko, N.N.',
        'firstname' => 'N.N.',
        'lastname' => 'Poleshko',
    ]);
    
    DB::connection('metaworks')->table('role')->insert([
        'resourceagent_resource_id' => $testResourceId,
        'resourceagent_order' => 2,
        'role' => 'Creator',
    ]);
    
    $dataset = new OldDataset();
    $dataset->id = $testResourceId;
    
    $authors = $dataset->getAuthors();
    
    expect($authors)->toHaveCount(2);
    
    // Prüfe ersten Autor (Natalya Mikhailova)
    $firstAuthor = $authors[0];
    expect($firstAuthor['name'])->toBe('Mikhailova, Natalya');
    expect($firstAuthor['givenName'])->toBe('Natalya');
    expect($firstAuthor['familyName'])->toBe('Mikhailova');
    expect($firstAuthor['roles'])->toContain('Creator');
    expect($firstAuthor['roles'])->toContain('ContactPerson');
    expect($firstAuthor['isContact'])->toBeTrue();
    expect($firstAuthor['affiliations'][0]['value'])->toBe('Institute of Geophysical Researches, Committee of Atomic Energy of the Republic of Kazakhstan, Almaty, Kazakhstan');
    
    // Prüfe zweiten Autor (ohne CP)
    $secondAuthor = $authors[1];
    expect($secondAuthor['name'])->toBe('Poleshko, N.N.');
    expect($secondAuthor['isContact'])->toBeFalse();
    expect($secondAuthor['affiliations'])->toBeEmpty();
});

it('lädt Autoren mit pointOfContact-Rolle und Email (Beispiel: Shahid Ullah)', function () {
    $testResourceId = 999999;
    
    // Füge echte Testdaten basierend auf Shahid Ullah (Resource 8, Order 10) ein
    // Hinweis: Wir fügen auch die Creator-Rolle hinzu, da getAuthors() nur Creator zurückgibt
    DB::connection('metaworks')->table('resourceagent')->insert([
        'resource_id' => $testResourceId,
        'order' => 1,
        'name' => 'Ullah, Shahid',
        'firstname' => null,
        'lastname' => null,
    ]);
    
    DB::connection('metaworks')->table('role')->insert([
        ['resourceagent_resource_id' => $testResourceId, 'resourceagent_order' => 1, 'role' => 'Creator'],
        ['resourceagent_resource_id' => $testResourceId, 'resourceagent_order' => 1, 'role' => 'pointOfContact'],
    ]);
    
    DB::connection('metaworks')->table('contactinfo')->insert([
        'resourceagent_resource_id' => $testResourceId,
        'resourceagent_order' => 1,
        'email' => 'ullah@gfz-potsdam.de',
        'website' => null,
    ]);
    
    DB::connection('metaworks')->table('affiliation')->insert([
        'resourceagent_resource_id' => $testResourceId,
        'resourceagent_order' => 1,
        'order' => 1,
        'name' => 'GFZ German Research Centre for Geosciences, Potsdam, Germany',
        'identifier' => '04z8jg394',
        'identifiertype' => 'ROR',
    ]);
    
    $dataset = new OldDataset();
    $dataset->id = $testResourceId;
    
    $authors = $dataset->getAuthors();
    
    expect($authors)->toHaveCount(1);
    $author = $authors[0];
    expect($author['name'])->toBe('Ullah, Shahid');
    expect($author['isContact'])->toBeTrue();
    expect($author['email'])->toBe('ullah@gfz-potsdam.de');
    expect($author['affiliations'][0]['value'])->toBe('GFZ German Research Centre for Geosciences, Potsdam, Germany');
    expect($author['affiliations'][0]['rorId'])->toBe('04z8jg394');
});

it('verwendet Fuzzy Matching für Kontaktinfos (Name ohne Komma)', function () {
    $testResourceId = 999998;
    
    // Füge Testdaten basierend auf Uhlemann, Steffi ein
    DB::connection('metaworks')->table('resourceagent')->insert([
        'resource_id' => $testResourceId,
        'order' => 1,
        'name' => 'Uhlemann, Steffi',
        'firstname' => null,
        'lastname' => null,
    ]);
    
    DB::connection('metaworks')->table('role')->insert([
        'resourceagent_resource_id' => $testResourceId,
        'resourceagent_order' => 1,
        'role' => 'Creator',
    ]);
    
    // Contactinfo mit leicht anderem Namen (ohne Komma) für Fuzzy Matching
    DB::connection('metaworks')->table('contactinfo')->insert([
        'resourceagent_resource_id' => $testResourceId,
        'resourceagent_order' => 1,
        'email' => 'steffi.uhlemann@example.org',
        'website' => null,
    ]);
    
    $dataset = new OldDataset();
    $dataset->id = $testResourceId;
    
    $authors = $dataset->getAuthors();
    
    expect($authors)->toHaveCount(1);
    $author = $authors[0];
    expect($author['name'])->toBe('Uhlemann, Steffi');
    expect($author['email'])->toBe('steffi.uhlemann@example.org');
    expect($author['isContact'])->toBeTrue(); // Weil Email gefunden wurde
});
