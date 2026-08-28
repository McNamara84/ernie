<?php

declare(strict_types=1);

use App\Models\Datacenter;
use App\Models\LandingPageTemplate;
use Illuminate\Database\Migrations\Migration;

function loadLandingPageTemplateNamesMigration(): Migration
{
    /** @var Migration $migration */
    $migration = require database_path(
        'migrations/2026_08_28_000001_rename_landing_page_copy_templates.php'
    );

    return $migration;
}

it('renames both copy templates without changing their identity or datacenter assignments', function (): void {
    $migration = loadLandingPageTemplateNamesMigration();
    $templates = LandingPageTemplate::ensureSystemTemplatesExist();
    $datacenter = Datacenter::factory()->create([
        'landing_page_template_id' => $templates['resource']->id,
        'igsn_landing_page_template_id' => $templates['igsn']->id,
    ]);

    $migration->down();

    expect($templates['resource']->fresh()?->name)->toBe('Default GFZ Data Services')
        ->and($templates['igsn']->fresh()?->name)->toBe('Default GFZ IGSN');

    $migration->up();

    expect($templates['resource']->fresh()?->name)->toBe('Templates Resources')
        ->and($templates['igsn']->fresh()?->name)->toBe('Templates IGSN')
        ->and($templates['resource']->fresh()?->slug)->toBe('default_gfz')
        ->and($templates['igsn']->fresh()?->slug)->toBe('default_gfz_igsn')
        ->and($datacenter->fresh()?->landing_page_template_id)->toBe($templates['resource']->id)
        ->and($datacenter->fresh()?->igsn_landing_page_template_id)->toBe($templates['igsn']->id);
});

it('refuses to overwrite a custom template with a target copy-template name', function (): void {
    $migration = loadLandingPageTemplateNamesMigration();
    LandingPageTemplate::ensureSystemTemplatesExist();
    $migration->down();

    LandingPageTemplate::factory()->create([
        'name' => 'Templates Resources',
        'slug' => 'custom-template-name-collision',
    ]);

    expect(fn () => $migration->up())
        ->toThrow(RuntimeException::class, 'custom-template-name-collision');

    expect(LandingPageTemplate::query()->where('slug', 'default_gfz')->value('name'))
        ->toBe('Default GFZ Data Services')
        ->and(LandingPageTemplate::query()->where('slug', 'default_gfz_igsn')->value('name'))
        ->toBe('Default GFZ IGSN');
});
