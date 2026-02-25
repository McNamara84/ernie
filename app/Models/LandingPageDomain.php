<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Domain entry for external landing page URLs.
 *
 * Domains are managed by admins/group leaders on the /settings page.
 * When a resource uses an "External Landing Page" template, the curator
 * selects a domain from this table and provides a path to compose the
 * full external URL.
 *
 * @property int $id
 * @property string $domain Full domain URL including protocol and trailing slash (e.g., "https://geofon.gfz.de/")
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \Illuminate\Database\Eloquent\Collection<int, LandingPage> $landingPages
 */
class LandingPageDomain extends Model
{
    /** @use HasFactory<\Database\Factories\LandingPageDomainFactory> */
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'domain',
    ];

    /**
     * Get landing pages using this domain.
     *
     * @return HasMany<LandingPage, $this>
     */
    public function landingPages(): HasMany
    {
        return $this->hasMany(LandingPage::class, 'external_domain_id');
    }
}
