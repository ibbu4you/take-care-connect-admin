<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A local copy of the website's category list.
 *
 * Deliberately a copy rather than a live lookup: this system has its own
 * database and does not talk to the site. The list can drift, which is why the
 * export bundle carries the category NAME rather than an id — a mismatch then
 * shows up as a label somebody can see, not a silently wrong link.
 */
class Category extends Model
{
    protected $fillable = ['name', 'slug', 'sort_order', 'is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'sort_order' => 'integer'];
    }

    /** @return HasMany<Article, $this> */
    public function articles(): HasMany
    {
        return $this->hasMany(Article::class);
    }
}
