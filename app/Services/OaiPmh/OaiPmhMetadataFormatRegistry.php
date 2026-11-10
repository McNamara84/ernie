<?php

declare(strict_types=1);

namespace App\Services\OaiPmh;

use App\Services\DcatAp\DcatApResourceProfileService;
use App\Services\Iso19115\Iso19115ResourceProfileService;

/** Shared by protocol discovery and the public harvester documentation. */
final class OaiPmhMetadataFormatRegistry
{
    public function __construct(private readonly Iso19115ResourceProfileService $iso, private readonly DcatApResourceProfileService $dcat) {}

    /** @return array<string, array{schema: string, namespace: string}> */
    public function formats(): array
    {
        $formats = config('oaipmh.metadata_formats', []);
        if (! $this->iso->isEnabled()) {
            unset($formats['iso19115_3']);
        }
        if ($this->dcat->isEnabled()) {
            $formats['dcat_ap'] = ['schema' => url('/schemas/oai-pmh/dcat-ap/3.0.1/rdf-envelope.xsd'), 'namespace' => (string) config('dcatap.namespace')];
        } else {
            unset($formats['dcat_ap']);
        }

        return $formats;
    }
}
