<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class OldDataset extends Model
{
    use HasFactory;

    /**
     * The connection name for the model.
     *
     * @var string
     */
    protected $connection = 'metaworks';

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'resource';

    /**
     * The primary key associated with the table.
     *
     * @var string
     */
    protected $primaryKey = 'id';

    /**
     * Indicates if the model should be timestamped.
     *
     * @var bool
     */
    public $timestamps = true;

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'publicationyear' => 'integer',
    ];

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'publicstatus',
        'identifier',
        'publisher',
        'publicationyear',
        'keywords',
        'version',
        'language',
        'identifiertype',
        'resourcetype',
        'resourcetypegeneral',
        'publicid',
        'progress',
        'curator',
    ];

    /**
     * Get all resources with their titles, ordered by created_at descending.
     *
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public static function getAllOrderedByCreatedDate()
    {
        return self::select([
                'resource.id',
                'resource.identifier',
                'resource.resourcetypegeneral',
                'resource.curator',
                'resource.created_at',
                'resource.updated_at',
                'resource.publicstatus',
                'resource.publisher',
                'resource.publicationyear',
                'resource.version',
                'resource.language',
                'title.title'
            ])
            ->leftJoin('title', 'resource.id', '=', 'title.resource_id')
            ->orderBy('resource.created_at', 'desc')
            ->get();
    }

    /**
     * Get paginated resources with their titles, ordered by created_at descending.
     *
     * @param int $page
     * @param int $perPage
     * @return \Illuminate\Contracts\Pagination\LengthAwarePaginator
     */
    public static function getPaginatedOrderedByCreatedDate($page = 1, $perPage = 50)
    {
        return self::select([
                'resource.id',
                'resource.identifier',
                'resource.resourcetypegeneral',
                'resource.curator',
                'resource.created_at',
                'resource.updated_at',
                'resource.publicstatus',
                'resource.publisher',
                'resource.publicationyear',
                'resource.version',
                'resource.language',
                'title.title'
            ])
            ->leftJoin('title', 'resource.id', '=', 'title.resource_id')
            ->orderBy('resource.created_at', 'desc')
            ->paginate($perPage, ['*'], 'page', $page);
    }
}