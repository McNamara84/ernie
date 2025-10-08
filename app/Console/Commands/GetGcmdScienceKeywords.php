<?php

namespace App\Console\Commands;

class GetGcmdScienceKeywords extends BaseGcmdCommand
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'get-gcmd-science-keywords';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Fetch GCMD Science Keywords from NASA KMS API and save as hierarchical JSON';

    /**
     * Get the vocabulary type
     */
    protected function getVocabularyType(): string
    {
        return 'sciencekeywords';
    }

    /**
     * Get the output filename
     */
    protected function getOutputFile(): string
    {
        return 'gcmd-science-keywords.json';
    }

    /**
     * Get the scheme title
     */
    protected function getSchemeTitle(): string
    {
        return 'NASA/GCMD Earth Science Keywords';
    }

    /**
     * Get the scheme URI
     */
    protected function getSchemeURI(): string
    {
        return 'https://gcmd.earthdata.nasa.gov/kms/concepts/concept_scheme/sciencekeywords';
    }

    /**
     * Get the display name
     */
    protected function getDisplayName(): string
    {
        return 'GCMD Science Keywords';
    }
}
