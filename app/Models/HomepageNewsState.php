<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $active_news_id
 * @property int $revision
 */
final class HomepageNewsState extends Model
{
    protected $table = 'homepage_news_state';

    /** @var list<string> */
    protected $guarded = ['id'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['active_news_id' => 'integer', 'revision' => 'integer'];
    }
}
