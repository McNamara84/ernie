<?php

declare(strict_types=1);

namespace App\Enums;

/** The curated topics and source motifs of the public GFZ homepage. */
enum ScienceTopic: string
{
    case Atmosphere = 'atmosphere';
    case Agriculture = 'agriculture';
    case Archaeobotany = 'archaeobotany';
    case Biosphere = 'biosphere';
    case ClimateScience = 'climate-science';
    case Cryosphere = 'cryosphere';
    case Geochemistry = 'geochemistry';
    case Geodetics = 'geodetics';
    case Geomagnetism = 'geomagnetism';
    case Geothermics = 'geothermics';
    case Gravity = 'gravity';
    case HumanDimensions = 'human-dimensions';
    case Hydrology = 'hydrology';
    case LandSurface = 'land-surface';
    case Modeling = 'modeling';
    case NaturalHazards = 'natural-hazards';
    case Oceans = 'oceans';
    case Paleoclimate = 'paleoclimate';
    case ScientificDrilling = 'scientific-drilling';
    case SolidEarth = 'solid-earth';
    case RemoteSensing = 'remote-sensing';
    case RocksMinerals = 'rocks-minerals';
    case SunEarthInteractions = 'sun-earth-interactions';
    case Seismology = 'seismology';
    case Volcanism = 'volcanism';

    public function label(): string
    {
        return match ($this) {
            self::Gravity => 'Gravity/ Gravitational Field',
            self::RocksMinerals => 'Rocks/ Minerals',
            self::SunEarthInteractions => 'Sun-Earth Interactions',
            default => ucwords(str_replace('-', ' ', $this->value)),
        };
    }

    public function image(): string
    {
        $filename = match ($this) {
            self::Geodetics => 'HEx_buttons_geodesy.png',
            self::Gravity => 'HEx_buttons_gravimetry.png',
            self::RocksMinerals => 'HEx_buttons_rocks.png',
            self::Volcanism => 'HEx_button_volcano-iceland-25.png',
            default => 'HEx_buttons_'.str_replace('-', '_', $this->value).'.png',
        };

        return '/images/home/topics/'.$filename;
    }

    /** Legacy sciencekeywordtree patterns, restricted to GCMD Science Keywords. */
    public function scienceKeywordPattern(): ?string
    {
        $pattern = match ($this) {
            self::Archaeobotany, self::Modeling, self::ScientificDrilling,
            self::RemoteSensing, self::Seismology => null,
            self::Biosphere => 'bio',
            self::ClimateScience => 'climate',
            self::Cryosphere => 'cryo',
            self::Geothermics => 'geotherm',
            self::Gravity => 'gravit',
            self::HumanDimensions => 'human.*dimensions',
            self::Hydrology => 'hydro',
            self::LandSurface => 'land.*surface',
            self::NaturalHazards => 'hazard',
            self::SolidEarth => 'solid.*earth',
            self::RocksMinerals => 'rocks',
            self::SunEarthInteractions => 'sun-earth.*interactions',
            self::Volcanism => 'volcan',
            default => $this->value,
        };

        return $pattern === null ? null : '/'.$pattern.'/iu';
    }

    /** @return array{slug: string, label: string} */
    public function selection(): array
    {
        return ['slug' => $this->value, 'label' => $this->label()];
    }

    /** @return array{slug: string, label: string, image: string, href: string} */
    public function forHomepage(): array
    {
        return [...$this->selection(), 'image' => $this->image(), 'href' => '/doi-search?topic='.$this->value];
    }
}
