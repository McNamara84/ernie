<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class OldDatasetTitle extends Model
{
    use HasFactory;

    /**
     * The connection name for the model.
     */
    protected $connection = 'metaworks';

    /**
     * The table associated with the model.
     */
    protected $table = 'title';

    /**
     * Indicates if the model should be timestamped.
     */
    public $timestamps = false;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'resource_id',
        'title',
        'titleType',
        'titletype',
    ];
}

