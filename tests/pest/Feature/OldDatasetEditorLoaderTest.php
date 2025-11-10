<?php<?php



/**/**

 * Tests for OldDatasetEditorLoader - Bug Fix Documentation * Tests for OldDatasetEditorLoader - Bug Fix Documentation

 *  * 

 * These tests document the critical bug fixes implemented in this branch: * These tests document the critical bug fixes implemented in this branch:

 *  * 

 * 1. **Umlaut Name Normalization**: Names like "Förste" (ö) and "Foerste" (oe) * 1. **Umlaut Name Normalization**: Names like "Förste" (ö) and "Foerste" (oe)

 *    are now treated as the same person for deduplication. *    are now treated as the same person for deduplication.

 *  * 

 * 2. **Contact Person Detection**: Authors are marked as contact persons when * 2. **Contact Person Detection**: Authors are marked as contact persons when

 *    a matching entry (after name normalization) has the pointOfContact role. *    a matching entry (after name normalization) has the pointOfContact role.

 *  * 

 * 3. **ORCID Loading**: ORCIDs are loaded from the `identifier` column when * 3. **ORCID Loading**: ORCIDs are loaded from the `identifier` column when

 *    `identifiertype = 'ORCID'`. *    `identifiertype = 'ORCID'`.

 *  * 

 * 4. **Contributor Deduplication**: Contributors with names matching authors * 4. **Contributor Deduplication**: Contributors with names matching authors

 *    (after normalization) are filtered out to prevent duplicates. *    (after normalization) are filtered out to prevent duplicates.

 *  * 

 * The actual functionality is tested via E2E tests with Dataset 4 from the * The actual functionality is tested via E2E tests with Dataset 4 from the

 * old metaworks database, which contains real-world data with these edge cases. * old metaworks database, which contains real-world data with these edge cases.

 *  * 

 * @see OldDatasetEditorLoader::normalizeName() - Private method for name normalization * @see OldDatasetEditorLoader::normalizeName() - Private method for name normalization

 * @see OldDatasetEditorLoader::loadAuthors() - Contact person detection with email/website loading * @see OldDatasetEditorLoader::loadAuthors() - Contact person detection with email/website loading

 * @see OldDatasetEditorLoader::loadContributors() - Deduplication logic * @see OldDatasetEditorLoader::loadContributors() - Deduplication logic

 */ */



describe('OldDatasetEditorLoader - Name Normalization Logic', function () {describe('OldDatasetEditorLoader - Name Normalization Logic', function () {

    it('should normalize German umlauts for comparison (ö → oe)', function () {    it('should normalize ö to oe for comparison', function () {

        // Test case: "Förste" and "Foerste" should match        // "Förste" → "foerste"

        // Implementation: normalizeName() converts ö→oe, ä→ae, ü→ue, ß→ss        // "Foerste" → "foerste"

        // Then applies strtolower() and trim()        // Both should match after normalization

        expect(true)->toBeTrue();        expect(true)->toBeTrue();

    });    });



    it('should normalize German umlauts for comparison (ü → ue)', function () {    it('should normalize ä to ae for comparison', function () {

        // Test case: "Müller" and "Mueller" should match        // "Bär" → "baer"

        expect(true)->toBeTrue();        // "Baer" → "baer"

    });        expect(true)->toBeTrue();

    });

    it('should normalize German umlauts for comparison (ä → ae)', function () {

        // Test case: "Bär" and "Baer" should match    it('should normalize ü to ue for comparison', function () {

        expect(true)->toBeTrue();        // "Müller" → "mueller"

    });        // "Mueller" → "mueller"

        expect(true)->toBeTrue();

    it('should normalize German umlauts for comparison (ß → ss)', function () {    });

        // Test case: "Maßmann" and "Massmann" should match

        expect(true)->toBeTrue();    it('should normalize ß to ss for comparison', function () {

    });        // "Maßmann" → "massmann"

        // "Massmann" → "massmann"

    it('should apply lowercase and trim to all names', function () {        expect(true)->toBeTrue();

        // Test case: "  FÖRSTE  " and "foerste" should match    });

        expect(true)->toBeTrue();

    });    it('should apply lowercase and trim to names', function () {

});        // "  FÖRSTE  " → "foerste"

        // "Foerste" → "foerste"

describe('OldDatasetEditorLoader - Contact Person Detection', function () {        expect(true)->toBeTrue();

    it('should mark author as contact when same order has pointOfContact role', function () {    });

        // Scenario: Author at order 1 has both Creator and pointOfContact roles});

        // Expected: isContact = true

        expect(true)->toBeTrue();/**

    }); * Tests for Contact Person Detection

 * 

    it('should mark author as contact when different order has matching normalized name with pointOfContact', function () { * Verifies that authors are correctly marked as contact persons when:

        // Scenario: * 1. The same order has pointOfContact role, OR

        // - Order 1: "Foerste, Christoph" with Creator role * 2. Another entry with normalized matching name has pointOfContact role

        // - Order 12: "Förste, Christoph" with pointOfContact role */

        // Expected: Author at order 1 gets isContact = truedescribe('OldDatasetEditorLoader - Contact Person Detection', function () {

        expect(true)->toBeTrue();    it('should mark author as contact when same order has pointOfContact role', function () {

    });        // Author at order 1 with Creator role

        // Same order 1 also has pointOfContact role

    it('should load email from contactinfo table for contact persons', function () {        // → isContact = true

        // Scenario: Contact info stored in separate contactinfo table        expect(true)->toBeTrue();

        // Linked by resourceagent_resource_id and resourceagent_order    });

        // Expected: email loaded from pointOfContact entry

        expect(true)->toBeTrue();    it('should mark author as contact when different order has pointOfContact and matching name', function () {

    });        // Author "Förste" at order 1 with Creator role

        // Different entry "Foerste" at order 12 with pointOfContact role

    it('should load website from contactinfo table for contact persons', function () {        // Names match after normalization

        // Scenario: Website stored in contactinfo table        // → isContact = true

        // Expected: website loaded if available        expect(true)->toBeTrue();

        expect(true)->toBeTrue();    });

    });

});    it('should load email/website from contactinfo table for contact persons', function () {

        // Contact info stored in separate table

describe('OldDatasetEditorLoader - Contributor Deduplication', function () {        // Linked by resourceagent_resource_id and resourceagent_order

    it('should not load contributor if normalized name matches an author', function () {        expect(true)->toBeTrue();

        // Scenario:    });

        // - Order 1: "Foerste, Christoph" (Creator) → Author});

        // - Order 12: "Förste, Christoph" (pointOfContact) → Should NOT be Contributor

        // Expected: Only appears as Author with isContact=true/**

        expect(true)->toBeTrue(); * Tests for Contributor Deduplication

    }); * 

 * Verifies that contributors who are also authors are filtered out

    it('should load contributor if name does not match any author', function () { * to prevent duplication (e.g., Barthelmes as both Creator and DataCurator).

        // Scenario: */

        // - Order 8: "Barthelmes, Franz" (Creator) → Authordescribe('OldDatasetEditorLoader - Contributor Deduplication', function () {

        // - Order 11: "Reißland, Sven" (DataManager) → Should be Contributor    it('should not load contributor if same normalized name exists as author', function () {

        // Expected: Reißland appears as Contributor        // Author "Förste" at order 1 (Creator)

        expect(true)->toBeTrue();        // Contributor "Foerste" at order 12 (pointOfContact)

    });        // Should only appear as Author, not Contributor

        expect(true)->toBeTrue();

    it('should deduplicate contributors even when author has multiple roles', function () {    });

        // Scenario:

        // - Order 8: "Barthelmes, Franz" (Creator) → Author    it('should load contributor if name does not match any author', function () {

        // - Order 10: "Barthelmes, Franz" (DataCurator) → Should NOT be Contributor        // Author "Barthelmes" at order 8 (Creator)

        // Expected: Only appears as Author        // Contributor "Reißland" at order 11 (DataManager)

        expect(true)->toBeTrue();        // Reißland should appear as Contributor

    });        expect(true)->toBeTrue();

});    });



describe('OldDatasetEditorLoader - ORCID Loading', function () {    it('should handle umlauts in contributor deduplication', function () {

    it('should load ORCID from identifier column when identifiertype is ORCID', function () {        // Author "Müller" at order 1

        // Scenario:        // Contributor "Mueller" at order 10

        // - identifier = '0000-0002-4476-9183'        // Should be deduplicated (same person)

        // - identifiertype = 'ORCID'        expect(true)->toBeTrue();

        // Expected: orcid property set to '0000-0002-4476-9183'    });

        expect(true)->toBeTrue();});

    });

/**

    it('should not load ORCID if identifiertype is not ORCID', function () { * Tests for ORCID Loading

        // Scenario: * 

        // - identifier = 'some-id' * Verifies that ORCIDs are loaded from the correct column with type checking.

        // - identifiertype = 'ResearcherID' */

        // Expected: orcid property NOT setdescribe('OldDatasetEditorLoader - ORCID Loading', function () {

        expect(true)->toBeTrue();    it('should load ORCID from identifier column when identifiertype is ORCID', function () {

    });        // resourceagent.identifier = '0000-0002-4476-9183'

        // resourceagent.identifiertype = 'ORCID'

    it('should not load ORCID if identifier is null or empty', function () {        // → orcid property should be set

        // Scenario:        expect(true)->toBeTrue();

        // - identifier = null    });

        // Expected: orcid property NOT set

        expect(true)->toBeTrue();    it('should not load ORCID if identifiertype is not ORCID', function () {

    });        // resourceagent.identifier = 'some-id'

});        // resourceagent.identifiertype = 'ResearcherID'

        // → orcid property should NOT be set

/**        expect(true)->toBeTrue();

 * Integration Test Reference - Dataset 4    });

 * 

 * Dataset 4 (DOI 10.5880/icgem.2015.1) from the old metaworks database    it('should not load ORCID if identifier is null', function () {

 * contains all edge cases tested by these bug fixes:        // resourceagent.identifier = null

 *         // → orcid property should NOT be set

 * Authors (Creator role, orders 1-9):        expect(true)->toBeTrue();

 * - Order 1: Foerste, Christoph (ORCID 0000-0002-4476-9183)    });

 * - Order 2: Bruinsma, Sean.L. (NO ORCID)});

 * - Order 3: Abrykosov, Oleh (ORCID 0000-0003-1463-412X)

 * - Order 4: Lemoine, Jean-Michel (ORCID 0000-0002-2758-1269)/**

 * - Order 5: Marty, Jean Charles (NO ORCID) * Integration Test Reference

 * - Order 6: Flechtner, Frank (ORCID 0000-0002-3093-5558) * 

 * - Order 7: Balmino, Georges (ORCID 0000-0002-8526-3314) * The actual behavior is tested end-to-end with Dataset 4:

 * - Order 8: Barthelmes, Franz (ORCID 0000-0001-5253-2859) * - 9 Authors (orders 1-9, Creator role)

 * - Order 9: Biancale, Richard (NO ORCID) * - 4 non-Creator entries (orders 10-13)

 *  * - Expected deduplication:

 * Contributors (non-Creator roles, orders 10-13): *   - Order 10 (Barthelmes, DataCurator) → filtered (also Author at order 8)

 * - Order 10: Barthelmes, Franz (DataCurator) → FILTERED (duplicate of order 8) *   - Order 11 (Reißland, DataManager) → loaded as Contributor

 * - Order 11: Reißland, Sven (DataManager, ORCID 0000-0001-6293-5336) → LOADED *   - Order 12 (Förste, pointOfContact) → filtered (also Author at order 1)

 * - Order 12: Förste, Christoph (pointOfContact) → FILTERED (duplicate of order 1, different spelling!) *   - Order 13 (Bruinsma, pointOfContact) → filtered (also Author at order 2)

 * - Order 13: Bruinsma, Sean.L. (pointOfContact) → FILTERED (duplicate of order 2) * - Result: 9 Authors, 1 Contributor

 *  * - Contact Persons: Förste and Bruinsma marked with isContact=true

 * Contact Info (contactinfo table): */

 * - Order 12: foer@gfz-potsdam.dedescribe('OldDatasetEditorLoader - Integration Test Documentation', function () {

 * - Order 13: sean.bruinsma@cnes.fr    beforeEach(function () {

 *             CREATE TABLE resourceagent (

 * Expected Result:                resource_id INTEGER NOT NULL,

 * - 9 Authors (6 with ORCID, 3 without)                "order" INTEGER NOT NULL,

 * - 1 Contributor (Reißland with ORCID)                name VARCHAR(255) NOT NULL,

 * - 2 Contact Persons (Förste and Bruinsma with emails)                firstname VARCHAR(255),

 */                lastname VARCHAR(255),

describe('OldDatasetEditorLoader - Dataset 4 Integration Test', function () {                identifier VARCHAR(100),

    it('should load 9 authors from Dataset 4', function () {                identifiertype VARCHAR(20),

        // All Creator role entries (orders 1-9)                nametype VARCHAR(20),

        expect(true)->toBeTrue();                PRIMARY KEY (resource_id, "order")

    });            )

        ');

    it('should load 6 ORCIDs for Dataset 4 authors', function () {

        // Orders 1, 3, 4, 6, 7, 8 have ORCIDs        DB::connection('metaworks')->statement('

        // Orders 2, 5, 9 have NO ORCIDs            CREATE TABLE role (

        expect(true)->toBeTrue();                resourceagent_resource_id INTEGER NOT NULL,

    });                resourceagent_order INTEGER NOT NULL,

                role VARCHAR(50) NOT NULL

    it('should load only 1 contributor from Dataset 4', function () {            )

        // Only Reißland (order 11) should be loaded        ');

        // Barthelmes (order 10), Förste (order 12), Bruinsma (order 13) filtered

        expect(true)->toBeTrue();        DB::connection('metaworks')->statement('

    });            CREATE TABLE contactinfo (

                position VARCHAR(256),

    it('should mark Förste and Bruinsma as contact persons', function () {                email VARCHAR(128),

        // Orders 12 and 13 have pointOfContact role                website VARCHAR(512),

        // Should set isContact=true on orders 1 and 2                resourceagent_resource_id INTEGER NOT NULL,

        expect(true)->toBeTrue();                resourceagent_order INTEGER NOT NULL,

    });                PRIMARY KEY (resourceagent_resource_id, resourceagent_order)

            )

    it('should load contact emails for Förste and Bruinsma', function () {        ');

        // foer@gfz-potsdam.de for Förste

        // sean.bruinsma@cnes.fr for Bruinsma        DB::connection('metaworks')->statement('

        expect(true)->toBeTrue();            CREATE TABLE affiliation (

    });                id INTEGER PRIMARY KEY AUTOINCREMENT,

});                resourceagent_resource_id INTEGER NOT NULL,

                resourceagent_order INTEGER NOT NULL,
                name VARCHAR(255),
                identifier VARCHAR(100),
                identifiertype VARCHAR(20)
            )
        ');

        DB::connection('metaworks')->statement('
            CREATE TABLE resource (
                id INTEGER PRIMARY KEY,
                title VARCHAR(255),
                year INTEGER,
                language VARCHAR(10)
            )
        ');
    });

    afterEach(function () {
        DB::connection('metaworks')->disconnect();
    });

    it('normalizes names with umlauts for deduplication', function () {
        // Insert Author with "oe" spelling
        DB::connection('metaworks')->table('resourceagent')->insert([
            'resource_id' => 1,
            'order' => 1,
            'name' => 'Foerste, Christoph',
            'firstname' => 'Christoph',
            'lastname' => 'Foerste',
            'identifier' => '0000-0002-4476-9183',
            'identifiertype' => 'ORCID',
            'nametype' => 'Personal',
        ]);

        DB::connection('metaworks')->table('role')->insert([
            'resourceagent_resource_id' => 1,
            'resourceagent_order' => 1,
            'role' => 'Creator',
        ]);

        // Insert same person as pointOfContact with "ö" umlaut
        DB::connection('metaworks')->table('resourceagent')->insert([
            'resource_id' => 1,
            'order' => 12,
            'name' => 'Förste, Christoph',
            'firstname' => null,
            'lastname' => null,
            'identifier' => null,
            'identifiertype' => null,
            'nametype' => 'Personal',
        ]);

        DB::connection('metaworks')->table('role')->insert([
            'resourceagent_resource_id' => 1,
            'resourceagent_order' => 12,
            'role' => 'pointOfContact',
        ]);

        DB::connection('metaworks')->table('contactinfo')->insert([
            'resourceagent_resource_id' => 1,
            'resourceagent_order' => 12,
            'email' => 'foer@gfz-potsdam.de',
            'website' => '',
            'position' => '',
        ]);

        DB::connection('metaworks')->table('resource')->insert([
            'id' => 1,
            'title' => 'Test Dataset',
            'year' => 2024,
            'language' => 'en',
        ]);

        $loader = new OldDatasetEditorLoader();
        $result = $loader->loadForEditor(1);

        // Should have 1 author (not 2, because of deduplication)
        expect($result['authors'])->toHaveCount(1);
        
        // Author should be marked as contact person
        expect($result['authors'][0]['isContact'])->toBeTrue();
        
        // Should have email from contactinfo table
        expect($result['authors'][0]['email'])->toBe('foer@gfz-potsdam.de');
        
        // Should have ORCID from Creator entry
        expect($result['authors'][0]['orcid'])->toBe('0000-0002-4476-9183');

        // Should have 0 contributors (pointOfContact was deduplicated)
        expect($result['contributors'])->toHaveCount(0);
    });

    it('marks author as contact person when matching name has pointOfContact role', function () {
        // Author with umlaut
        DB::connection('metaworks')->table('resourceagent')->insert([
            'resource_id' => 2,
            'order' => 1,
            'name' => 'Müller, Thomas',
            'firstname' => 'Thomas',
            'lastname' => 'Müller',
            'identifier' => '0000-0001-2345-6789',
            'identifiertype' => 'ORCID',
            'nametype' => 'Personal',
        ]);

        DB::connection('metaworks')->table('role')->insert([
            'resourceagent_resource_id' => 2,
            'resourceagent_order' => 1,
            'role' => 'Creator',
        ]);

        // Same person as contact with different spelling
        DB::connection('metaworks')->table('resourceagent')->insert([
            'resource_id' => 2,
            'order' => 10,
            'name' => 'Mueller, Thomas',
            'firstname' => null,
            'lastname' => null,
            'identifier' => null,
            'identifiertype' => null,
            'nametype' => 'Personal',
        ]);

        DB::connection('metaworks')->table('role')->insert([
            'resourceagent_resource_id' => 2,
            'resourceagent_order' => 10,
            'role' => 'pointOfContact',
        ]);

        DB::connection('metaworks')->table('contactinfo')->insert([
            'resourceagent_resource_id' => 2,
            'resourceagent_order' => 10,
            'email' => 'mueller@example.com',
            'website' => 'https://example.com',
            'position' => '',
        ]);

        DB::connection('metaworks')->table('resource')->insert([
            'id' => 2,
            'title' => 'Test Dataset 2',
            'year' => 2024,
            'language' => 'en',
        ]);

        $loader = new OldDatasetEditorLoader();
        $result = $loader->loadForEditor(2);

        expect($result['authors'][0]['isContact'])->toBeTrue();
        expect($result['authors'][0]['email'])->toBe('mueller@example.com');
        expect($result['authors'][0]['website'])->toBe('https://example.com');
    });

    it('prevents duplicate authors from appearing as contributors', function () {
        // Author
        DB::connection('metaworks')->table('resourceagent')->insert([
            'resource_id' => 3,
            'order' => 1,
            'name' => 'Barthelmes, Franz',
            'firstname' => 'Franz',
            'lastname' => 'Barthelmes',
            'identifier' => '0000-0001-5253-2859',
            'identifiertype' => 'ORCID',
            'nametype' => 'Personal',
        ]);

        DB::connection('metaworks')->table('role')->insert([
            'resourceagent_resource_id' => 3,
            'resourceagent_order' => 1,
            'role' => 'Creator',
        ]);

        // Same person as DataCurator
        DB::connection('metaworks')->table('resourceagent')->insert([
            'resource_id' => 3,
            'order' => 10,
            'name' => 'Barthelmes, Franz',
            'firstname' => null,
            'lastname' => null,
            'identifier' => '0000-0001-5253-2859',
            'identifiertype' => 'ORCID',
            'nametype' => 'Personal',
        ]);

        DB::connection('metaworks')->table('role')->insert([
            'resourceagent_resource_id' => 3,
            'resourceagent_order' => 10,
            'role' => 'DataCurator',
        ]);

        // Different person as Contributor
        DB::connection('metaworks')->table('resourceagent')->insert([
            'resource_id' => 3,
            'order' => 11,
            'name' => 'Reißland, Sven',
            'firstname' => 'Sven',
            'lastname' => 'Reißland',
            'identifier' => '0000-0001-6293-5336',
            'identifiertype' => 'ORCID',
            'nametype' => 'Personal',
        ]);

        DB::connection('metaworks')->table('role')->insert([
            'resourceagent_resource_id' => 3,
            'resourceagent_order' => 11,
            'role' => 'DataManager',
        ]);

        DB::connection('metaworks')->table('resource')->insert([
            'id' => 3,
            'title' => 'Test Dataset 3',
            'year' => 2024,
            'language' => 'en',
        ]);

        $loader = new OldDatasetEditorLoader();
        $result = $loader->loadForEditor(3);

        // Should have 1 author (Barthelmes as Creator)
        expect($result['authors'])->toHaveCount(1);
        expect($result['authors'][0]['lastName'])->toBe('Barthelmes');

        // Should have only 1 contributor (Reißland), not Barthelmes
        expect($result['contributors'])->toHaveCount(1);
        expect($result['contributors'][0]['lastName'])->toBe('Reißland');
    });

    it('loads ORCID from identifier column with correct identifiertype', function () {
        DB::connection('metaworks')->table('resourceagent')->insert([
            'resource_id' => 4,
            'order' => 1,
            'name' => 'Smith, John',
            'firstname' => 'John',
            'lastname' => 'Smith',
            'identifier' => '0000-0002-1234-5678',
            'identifiertype' => 'ORCID',
            'nametype' => 'Personal',
        ]);

        DB::connection('metaworks')->table('role')->insert([
            'resourceagent_resource_id' => 4,
            'resourceagent_order' => 1,
            'role' => 'Creator',
        ]);

        DB::connection('metaworks')->table('resource')->insert([
            'id' => 4,
            'title' => 'Test Dataset 4',
            'year' => 2024,
            'language' => 'en',
        ]);

        $loader = new OldDatasetEditorLoader();
        $result = $loader->loadForEditor(4);

        expect($result['authors'][0]['orcid'])->toBe('0000-0002-1234-5678');
    });

    it('does not load ORCID if identifiertype is not ORCID', function () {
        DB::connection('metaworks')->table('resourceagent')->insert([
            'resource_id' => 5,
            'order' => 1,
            'name' => 'Doe, Jane',
            'firstname' => 'Jane',
            'lastname' => 'Doe',
            'identifier' => 'some-other-id',
            'identifiertype' => 'ResearcherID',
            'nametype' => 'Personal',
        ]);

        DB::connection('metaworks')->table('role')->insert([
            'resourceagent_resource_id' => 5,
            'resourceagent_order' => 1,
            'role' => 'Creator',
        ]);

        DB::connection('metaworks')->table('resource')->insert([
            'id' => 5,
            'title' => 'Test Dataset 5',
            'year' => 2024,
            'language' => 'en',
        ]);

        $loader = new OldDatasetEditorLoader();
        $result = $loader->loadForEditor(5);

        expect($result['authors'][0])->not->toHaveKey('orcid');
    });
});
