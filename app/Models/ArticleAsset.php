<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

/**
 * An image or file attached to a draft.
 *
 * These live on the private workfiles disk and travel out in the export bundle,
 * because whoever publishes on the live site has to upload them there — this
 * system has no access to the site's media library.
 */
class ArticleAsset extends Model
{
    protected $fillable = [
        'article_id', 'kind', 'label', 'disk', 'path',
        'original_filename', 'mime_type', 'size_bytes', 'url', 'alt_text', 'uploaded_by',
    ];

    protected function casts(): array
    {
        return ['size_bytes' => 'integer'];
    }

    /** @return BelongsTo<Article, $this> */
    public function article(): BelongsTo
    {
        return $this->belongsTo(Article::class);
    }

    /** @return BelongsTo<User, $this> */
    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function isLink(): bool
    {
        return blank($this->path) && filled($this->url);
    }

    public function target(): ?string
    {
        return $this->isLink() ? $this->url : route('articles.assets.download', $this);
    }

    public function deleteFile(): void
    {
        if (filled($this->path)) {
            Storage::disk($this->disk)->delete($this->path);
        }
    }
}
