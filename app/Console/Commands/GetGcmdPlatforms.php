<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;

#[Description('Fetch GCMD Platforms from NASA KMS API and save as hierarchical JSON')]
#[Signature('get-gcmd-platforms')]
class GetGcmdPlatforms extends BaseGcmdCommand
{

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
