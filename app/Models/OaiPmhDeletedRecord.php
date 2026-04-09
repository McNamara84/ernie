<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Tracks deleted/depublished records for OAI-PMH persistent deletion support.
 *
 * When a published resource is deleted or its landing page is depublished,
 * a record is created here so that OAI-PMH harvesters are informed via
 * the status="deleted" header attribute.
 *
 * @property int $id
 * @property string $oai_identifier OAI identifier (e.g., "oai:ernie.gfz.de:10.5880/GFZ.1.2.2024.001")
 * @property string $doi Original DOI of the deleted resource
 * @property \Illuminate\Support\Carbon $datestamp When the record was deleted/depublished
 * @property array<int, string>|null $sets OAI sets the record belonged to
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 */
class OaiPmhDeletedRecord extends Model
{
    /** @var list<string> */
    protected $fillable = [
        'oai_identifier',
        'doi',
        'datestamp',
        'sets',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'datestamp' => 'datetime',
            'sets' => 'array',
        ];
    }
}
