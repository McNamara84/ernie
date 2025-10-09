<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ResourceKeyword extends Model
{
    use HasFactory;

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'resource_keywords';

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'resource_id',
        'keyword',
    ];

    /**
     * Get the resource that owns this keyword.
     */
    public function resource(): BelongsTo
    {
        return $this->belongsTo(Resource::class);
    }
}
