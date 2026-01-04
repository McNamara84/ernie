<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\LandingPage;
use Illuminate\Console\Command;

/**
 * Validates DOI formats in landing pages.
 *
 * This command identifies landing pages with invalid DOI formats that would
 * be rejected by the LandingPagePublicController's validateDoiPrefixFormat() method.
 * Run this after migrations to ensure all landing pages are accessible.
 *
 * @see database/migrations/2026_01_03_050440_populate_doi_prefix_for_existing_landing_pages.php
 */
class ValidateLandingPageDois extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'landing-pages:validate-dois
                            {--fix : Attempt to fix common DOI format issues}
                            {--dry-run : Show what would be fixed without making changes}
                            {--strict : Exit with error code if any invalid DOIs are found (for CI/deployment)}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Validate DOI formats in landing pages and report invalid entries. Use --strict for deployment checks.';

    /**
     * DOI format pattern matching LandingPagePublicController::validateDoiPrefixFormat()
     *
     * Format: 10.N+/suffix where:
     * - 10. is the DOI prefix
     * - N+ is the registrant code (one or more digits)
     * - / separates prefix from suffix
     * - suffix is any non-empty string
     */
    private const DOI_PATTERN = '/^10\.\d+\/.+$/';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->info('Validating landing page DOI formats...');
        $this->newLine();

        $fix = $this->option('fix');
        $dryRun = $this->option('dry-run');

        // Get all landing pages with non-null doi_prefix
        $landingPages = LandingPage::query()
            ->whereNotNull('doi_prefix')
            ->with(['resource:id,doi'])
            ->get(['id', 'resource_id', 'doi_prefix', 'slug']);

        $invalidCount = 0;
        $fixedCount = 0;
        $tableData = [];

        foreach ($landingPages as $landingPage) {
            // doi_prefix is guaranteed non-null due to whereNotNull() above
            /** @var string $doiPrefix */
            $doiPrefix = $landingPage->doi_prefix;

            if (preg_match(self::DOI_PATTERN, $doiPrefix) === 1) {
                continue; // Valid DOI format
            }

            $invalidCount++;

            $resourceDoi = $landingPage->resource->doi ?? 'N/A';
            $suggestion = $this->suggestFix($doiPrefix);

            $tableData[] = [
                'ID' => $landingPage->id,
                'Resource ID' => $landingPage->resource_id,
                'DOI Prefix' => $landingPage->doi_prefix,
                'Resource DOI' => $resourceDoi,
                'Suggested Fix' => $suggestion ?? 'Manual review required',
            ];

            // Attempt fix if requested
            if ($fix && $suggestion !== null && ! $dryRun) {
                $landingPage->doi_prefix = $suggestion;
                $landingPage->save();
                $fixedCount++;
            }
        }

        if ($invalidCount === 0) {
            $this->info('✓ All landing page DOIs are valid.');

            return Command::SUCCESS;
        }

        $this->warn("Found {$invalidCount} landing page(s) with invalid DOI formats:");
        $this->newLine();

        $this->table(
            ['ID', 'Resource ID', 'DOI Prefix', 'Resource DOI', 'Suggested Fix'],
            $tableData
        );

        $this->newLine();

        if ($dryRun) {
            $this->info('Dry run mode - no changes made.');
        } elseif ($fix) {
            $this->info("Fixed {$fixedCount} of {$invalidCount} invalid DOIs.");
            if ($fixedCount < $invalidCount) {
                $this->warn('Some DOIs require manual review.');
            }
        } else {
            $this->line('');
            $this->line('These landing pages will return 404 errors until their DOIs are corrected.');
            $this->line('');
            $this->line('Options:');
            $this->line('  --fix      Attempt to automatically fix common format issues');
            $this->line('  --dry-run  Show what would be fixed without making changes');
            $this->line('');
            $this->line('For manual fixes, update the doi_prefix column directly or fix the');
            $this->line('resource DOI and re-run migration 2026_01_03_050440.');
        }

        return Command::FAILURE;
    }

    /**
     * Suggest a fix for common DOI format issues.
     *
     * @return string|null Suggested fix, or null if manual review is needed
     */
    private function suggestFix(string $doi): ?string
    {
        // Common issue: Missing "10." prefix
        if (preg_match('/^\d{4,}\/.+$/', $doi)) {
            return '10.' . $doi;
        }

        // Common issue: Wrong separator (e.g., "10.5880-GFZ" instead of "10.5880/GFZ")
        if (preg_match('/^10\.\d+[-_]/', $doi)) {
            return preg_replace('/^(10\.\d+)[-_]/', '$1/', $doi);
        }

        // Common issue: Extra spaces
        $trimmed = trim($doi);
        if ($trimmed !== $doi && preg_match(self::DOI_PATTERN, $trimmed) === 1) {
            return $trimmed;
        }

        // Common issue: URL instead of DOI
        if (str_starts_with($doi, 'https://doi.org/')) {
            $extracted = substr($doi, strlen('https://doi.org/'));
            if (preg_match(self::DOI_PATTERN, $extracted) === 1) {
                return $extracted;
            }
        }

        return null;
    }
}
