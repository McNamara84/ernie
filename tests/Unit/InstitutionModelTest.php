<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\Institution;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Unit tests for Institution Model with identifier fields
 * Tests the extended Institution model with identifier and identifier_type support
 */
class InstitutionModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_can_create_institution_with_labid(): void
    {
        $institution = Institution::create([
            'name' => 'Test Laboratory',
            'identifier' => 'abc123',
            'identifier_type' => 'labid',
        ]);

        $this->assertDatabaseHas('institutions', [
            'name' => 'Test Laboratory',
            'identifier' => 'abc123',
            'identifier_type' => 'labid',
        ]);

        $this->assertEquals('abc123', $institution->identifier);
        $this->assertEquals('labid', $institution->identifier_type);
    }

    public function test_can_create_institution_with_ror(): void
    {
        $institution = Institution::create([
            'name' => 'Test University',
            'identifier' => 'https://ror.org/test123',
            'identifier_type' => 'ROR',
        ]);

        $this->assertDatabaseHas('institutions', [
            'name' => 'Test University',
            'identifier' => 'https://ror.org/test123',
            'identifier_type' => 'ROR',
        ]);

        $this->assertEquals('https://ror.org/test123', $institution->identifier);
        $this->assertEquals('ROR', $institution->identifier_type);
    }

    public function test_can_create_institution_with_legacy_ror_id(): void
    {
        $institution = Institution::create([
            'name' => 'Legacy University',
            'ror_id' => 'https://ror.org/legacy',
        ]);

        $this->assertDatabaseHas('institutions', [
            'name' => 'Legacy University',
            'ror_id' => 'https://ror.org/legacy',
        ]);

        $this->assertEquals('https://ror.org/legacy', $institution->ror_id);
    }

    public function test_can_create_institution_without_identifier(): void
    {
        $institution = Institution::create([
            'name' => 'Simple Institution',
        ]);

        $this->assertDatabaseHas('institutions', [
            'name' => 'Simple Institution',
            'identifier' => null,
            'identifier_type' => null,
        ]);

        $this->assertNull($institution->identifier);
        $this->assertNull($institution->identifier_type);
    }

    public function test_is_laboratory_returns_true_for_labid(): void
    {
        $lab = Institution::create([
            'name' => 'Test Lab',
            'identifier' => 'lab123',
            'identifier_type' => 'labid',
        ]);

        $this->assertTrue($lab->isLaboratory());
    }

    public function test_is_laboratory_returns_false_for_non_labid(): void
    {
        $institution = Institution::create([
            'name' => 'Test University',
            'identifier' => 'https://ror.org/test',
            'identifier_type' => 'ROR',
        ]);

        $this->assertFalse($institution->isLaboratory());
    }

    public function test_is_laboratory_returns_false_when_no_identifier_type(): void
    {
        $institution = Institution::create([
            'name' => 'Simple Institution',
        ]);

        $this->assertFalse($institution->isLaboratory());
    }

    public function test_is_ror_institution_returns_true_for_ror(): void
    {
        $institution = Institution::create([
            'name' => 'ROR University',
            'identifier' => 'https://ror.org/test',
            'identifier_type' => 'ROR',
        ]);

        $this->assertTrue($institution->isRorInstitution());
    }

    public function test_is_ror_institution_returns_false_for_non_ror(): void
    {
        $lab = Institution::create([
            'name' => 'Test Lab',
            'identifier' => 'lab123',
            'identifier_type' => 'labid',
        ]);

        $this->assertFalse($lab->isRorInstitution());
    }

    public function test_is_ror_institution_returns_false_when_no_identifier_type(): void
    {
        $institution = Institution::create([
            'name' => 'Simple Institution',
        ]);

        $this->assertFalse($institution->isRorInstitution());
    }

    public function test_unique_constraint_on_identifier_and_type(): void
    {
        // Create first institution
        Institution::create([
            'name' => 'First Lab',
            'identifier' => 'same123',
            'identifier_type' => 'labid',
        ]);

        // Try to create duplicate - should fail
        $this->expectException(\Illuminate\Database\QueryException::class);

        Institution::create([
            'name' => 'Second Lab',
            'identifier' => 'same123',
            'identifier_type' => 'labid',
        ]);
    }

    public function test_same_identifier_different_type_is_allowed(): void
    {
        // Create with labid
        $lab = Institution::create([
            'name' => 'Lab',
            'identifier' => 'abc123',
            'identifier_type' => 'labid',
        ]);

        // Create with different type (hypothetical scenario)
        $other = Institution::create([
            'name' => 'Other',
            'identifier' => 'abc123',
            'identifier_type' => 'ISNI',
        ]);

        $this->assertNotEquals($lab->id, $other->id);
    }

    public function test_can_update_institution_identifier(): void
    {
        $institution = Institution::create([
            'name' => 'Test Lab',
            'identifier' => 'old123',
            'identifier_type' => 'labid',
        ]);

        $institution->update([
            'identifier' => 'new456',
        ]);

        $this->assertEquals('new456', $institution->fresh()->identifier);
    }

    public function test_can_update_institution_name(): void
    {
        $institution = Institution::create([
            'name' => 'Old Name',
            'identifier' => 'lab123',
            'identifier_type' => 'labid',
        ]);

        $institution->update([
            'name' => 'New Name',
        ]);

        $this->assertEquals('New Name', $institution->fresh()->name);
    }

    public function test_fillable_includes_new_fields(): void
    {
        $institution = new Institution;
        $fillable = $institution->getFillable();

        $this->assertContains('identifier', $fillable);
        $this->assertContains('identifier_type', $fillable);
        $this->assertContains('name', $fillable);
        $this->assertContains('ror_id', $fillable);
    }
}
