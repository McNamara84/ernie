<?php

namespace App\Console\Commands;

class GetGcmdPlatforms extends BaseGcmdCommand
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'get-gcmd-platforms';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Fetch GCMD Platforms from NASA KMS API and save as hierarchical JSON';

    /**
     * Get the vocabulary type
     */
    protected function getVocabularyType(): string
    {
        return 'platforms';
    }

    /**
     * Get the output filename
     */
    protected function getOutputFile(): string
    {
        return 'gcmd-platforms.json';
    }

    /**
     * Get the scheme title
     */
    protected function getSchemeTitle(): string
    {
        return 'NASA/GCMD Earth Platforms Keywords';
    }

    /**
     * Get the scheme URI
     */
    protected function getSchemeURI(): string
    {
        return 'https://gcmd.earthdata.nasa.gov/kms/concepts/concept_scheme/platforms';
    }

    /**
     * Get the display name
     */
    protected function getDisplayName(): string
    {
        return 'GCMD Platforms';
    }
}
