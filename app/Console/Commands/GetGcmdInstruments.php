<?php

namespace App\Console\Commands;

class GetGcmdInstruments extends BaseGcmdCommand
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'get-gcmd-instruments';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Fetch GCMD Instruments from NASA KMS API and save as hierarchical JSON';

    /**
     * Get the vocabulary type
     */
    protected function getVocabularyType(): string
    {
        return 'instruments';
    }

    /**
     * Get the output filename
     */
    protected function getOutputFile(): string
    {
        return 'gcmd-instruments.json';
    }

    /**
     * Get the scheme title
     */
    protected function getSchemeTitle(): string
    {
        return 'NASA/GCMD Instruments';
    }

    /**
     * Get the scheme URI
     */
    protected function getSchemeURI(): string
    {
        return 'https://gcmd.earthdata.nasa.gov/kms/concepts/concept_scheme/instruments';
    }

    /**
     * Get the display name
     */
    protected function getDisplayName(): string
    {
        return 'GCMD Instruments';
    }
}
