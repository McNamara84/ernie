<?php

declare(strict_types=1);

namespace App\Services\OaiPmh;

use App\Enums\DcatApFormat;
use App\Services\Iso19115\Iso19115ResourceProfileService;

/** Shared by protocol discovery and the public harvester documentation. */
final class OaiPmhMetadataFormatRegistry
{
    public function __construct(private readonly Iso19115ResourceProfileService $iso) {}

    /** @return array<string, array{schema: string, namespace: string}> */
    public function formats(): array
    {
        $formats = config('oaipmh.metadata_formats', []);
        if (! $this->iso->isEnabled()) {
            unset($formats['iso19115_3']);
        }
        foreach (DcatApFormat::cases() as $format) {
            if ($format->enabled()) {
                $formats[$format->value] = ['schema' => $format->schemaUrl(), 'namespace' => (string) config($format->configKey().'.namespace')];
            } else {
                unset($formats[$format->value]);
            }
        }

        return $formats;
    }
}
