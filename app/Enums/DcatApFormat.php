<?php

declare(strict_types=1);

namespace App\Enums;

use App\Services\DcatAp\DcatApRdfXmlExporter;
use App\Services\DcatAp\DcatRdfXmlExportService;
use App\Services\DcatAp\EposDcatApRdfXmlExportService;

enum DcatApFormat: string
{
    case Eu = 'dcat_ap';
    case Epos = 'epos_dcat_ap';

    public function configKey(): string
    {
        return $this === self::Eu ? 'dcatap' : 'dcatap.epos';
    }

    public function enabled(): bool
    {
        return (bool) config($this->configKey().'.enabled', false);
    }

    /** @return list<self> */
    public static function enabledFormats(): array
    {
        return array_values(array_filter(self::cases(), static fn (self $format): bool => $format->enabled()));
    }

    public function slug(): string
    {
        return str_replace('_', '-', $this->value);
    }

    public function label(): string
    {
        return strtoupper($this->slug()).' '.config($this->configKey().'.version');
    }

    public function schemaUrl(): string
    {
        return url('/schemas/oai-pmh/'.$this->slug().'/'.config($this->configKey().'.version').'/rdf-envelope.xsd');
    }

    public function exporter(): DcatRdfXmlExportService
    {
        return match ($this) {
            self::Eu => app(DcatApRdfXmlExporter::class),
            self::Epos => app(EposDcatApRdfXmlExportService::class),
        };
    }
}
